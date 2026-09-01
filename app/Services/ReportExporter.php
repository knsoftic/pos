<?php

namespace App\Services;

use App\Exceptions\FeatureUnavailableException;
use App\Support\FeatureRegistry;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Getting a report out of the screen and into something else (#56).
 *
 * ================= FOUR FORMATS, THREE GATES =================
 * CSV comes with the basic plan because a report you cannot get out of the
 * system is a report you cannot check, and a shop's accountant works in
 * whatever they already use. PDF and Excel are the paid formats, gated on
 * `reports.export_pdf` and `reports.export_excel`, and printing needs nothing
 * because it is just the page.
 *
 * ================= WHY THE .XLSX IS HAND-WRITTEN =================
 * A real spreadsheet is a ZIP holding a handful of XML parts, and PHP ships
 * ZipArchive. Writing those parts is about a hundred lines and gives numbers
 * that Excel treats as NUMBERS — sortable, summable, right-aligned — which is
 * the entire reason somebody asked for Excel rather than CSV. Pulling in a
 * spreadsheet library to do it would add several megabytes and a build step to
 * this project for the sake of one file format, and this codebase has
 * consistently preferred the small thing it can read (see Ean13).
 *
 * ⚠️ This writer covers what a report needs — one sheet, strings, numbers, a
 * bold header row — and nothing else. It is not a spreadsheet library, and it
 * should not grow into one.
 */
class ReportExporter
{
    public function __construct(protected FeatureService $features) {}

    /**
     * @param  array<string, mixed>  $report  a {@see ReportService::build()} result
     */
    public function download(array $report, string $format): SymfonyResponse
    {
        return match ($format) {
            'csv' => $this->csv($report),
            'xlsx' => $this->xlsx($report),
            'pdf' => $this->pdf($report),
            default => abort(404, 'No such export format.'),
        };
    }

    // ==================================================================== csv

    /** @param array<string, mixed> $report */
    public function csv(array $report): StreamedResponse
    {
        $filename = $this->filename($report, 'csv');

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');

            // ⚠️ Excel reads a CSV as the system codepage unless it finds a
            // byte-order mark. Without these three bytes, every shop with a
            // non-ASCII product name gets mojibake and blames the export.
            fwrite($out, "\xEF\xBB\xBF");

            // The title block: a file that has left the system has to say what
            // it is and which period it covers, or it is just a grid of numbers.
            fputcsv($out, [$report['name']]);
            fputcsv($out, [$this->periodLine($report)]);
            fputcsv($out, []);

            fputcsv($out, array_column($report['columns'], 'label'));

            foreach ($report['rows'] as $row) {
                fputcsv($out, $this->rowValues($report['columns'], $row));
            }

            if ($report['totals'] !== null) {
                fputcsv($out, $this->rowValues($report['columns'], $report['totals']));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // =================================================================== xlsx

    /** @param array<string, mixed> $report */
    public function xlsx(array $report): BinaryFileResponse
    {
        $this->assertFeature(FeatureRegistry::REPORTS_EXPORT_EXCEL);

        $path = tempnam(sys_get_temp_dir(), 'report').'.xlsx';
        $zip = new ZipArchive;

        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Could not build the spreadsheet.');

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRels());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRels());
        $zip->addFromString('xl/styles.xml', $this->xlsxStyles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheet($report));

        $zip->close();

        return response()->download($path, $this->filename($report, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    // ==================================================================== pdf

    /** @param array<string, mixed> $report */
    public function pdf(array $report): Response
    {
        $this->assertFeature(FeatureRegistry::REPORTS_EXPORT_PDF);

        $pdf = Pdf::loadView('app.reports.pdf', [
            'report' => $report,
            'business' => app(TenantContext::class)->business(),
            'printedAt' => now(),
        ]);

        // Reports are wide. Portrait would either shrink the type past reading
        // or cut the right-hand columns off, and a cut-off total is worse than
        // no export at all.
        $pdf->setPaper('a4', count($report['columns']) > 5 ? 'landscape' : 'portrait');

        return $pdf->download($this->filename($report, 'pdf'));
    }

    // ------------------------------------------------------------- internals

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, mixed>  $row
     * @return list<string|float|int|null>
     */
    protected function rowValues(array $columns, array $row): array
    {
        $values = [];

        foreach ($columns as $column) {
            $value = $row[$column['key']] ?? null;

            // Numbers go out as numbers, not as "1,234.50" — a thousands
            // separator is for reading, and a spreadsheet that receives one
            // cannot add the column up.
            $values[] = in_array($column['format'] ?? 'text', ['money', 'number', 'quantity', 'percent'], true)
                ? ($value === null ? null : (float) $value)
                : $value;
        }

        return $values;
    }

    /** @param array<string, mixed> $report */
    protected function periodLine(array $report): string
    {
        $meta = $report['meta'];

        if (($meta['dated'] ?? true) === false) {
            return 'As at '.now()->format('d M Y, H:i');
        }

        return sprintf(
            '%s to %s%s',
            Carbon::parse($meta['from'])->format('d M Y'),
            Carbon::parse($meta['to'])->format('d M Y'),
            $meta['branch'] ? ' · '.$meta['branch']->name : '',
        );
    }

    /** @param array<string, mixed> $report */
    protected function filename(array $report, string $extension): string
    {
        return sprintf(
            '%s-%s.%s',
            Str::slug($report['name']),
            now()->format('Y-m-d'),
            $extension,
        );
    }

    protected function assertFeature(string $code): void
    {
        if (! $this->features->enabled($code)) {
            throw new FeatureUnavailableException($code, FeatureRegistry::all()[$code]['name'] ?? 'This export');
        }
    }

    // ------------------------------------------------- the spreadsheet parts

    protected function xlsxContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    protected function xlsxRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    protected function xlsxWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    protected function xlsxWorkbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * Four styles, which is all a report needs: plain, bold (title and header),
     * money (two decimals with thousands) and bold money for the totals row.
     */
    protected function xlsxStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                             // 0 plain
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'               // 1 bold
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'     // 2 money
            .'<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>' // 3 bold money
            .'</cellXfs>'
            .'</styleSheet>';
    }

    /** @param array<string, mixed> $report */
    protected function xlsxSheet(array $report): string
    {
        $rows = [];
        $line = 1;

        // Title block, same as the CSV: a file has to say what it is.
        $rows[] = $this->xlsxRow($line++, [['v' => $report['name'], 'type' => 'text', 'style' => 1]]);
        $rows[] = $this->xlsxRow($line++, [['v' => $this->periodLine($report), 'type' => 'text', 'style' => 0]]);
        $line++; // one blank line

        $rows[] = $this->xlsxRow($line++, array_map(
            fn (array $column) => ['v' => $column['label'], 'type' => 'text', 'style' => 1],
            $report['columns'],
        ));

        foreach ($report['rows'] as $row) {
            $rows[] = $this->xlsxRow($line++, $this->xlsxCells($report['columns'], $row, bold: false));
        }

        if ($report['totals'] !== null) {
            $rows[] = $this->xlsxRow($line++, $this->xlsxCells($report['columns'], $report['totals'], bold: true));
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $rows).'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, mixed>  $row
     * @return list<array{v: mixed, type: string, style: int}>
     */
    protected function xlsxCells(array $columns, array $row, bool $bold): array
    {
        $cells = [];

        foreach ($columns as $column) {
            $value = $row[$column['key']] ?? null;
            $numeric = in_array($column['format'] ?? 'text', ['money', 'number', 'quantity', 'percent'], true);

            $cells[] = [
                'v' => $value,
                'type' => $numeric && $value !== null && $value !== '' ? 'number' : 'text',
                'style' => $numeric ? ($bold ? 3 : 2) : ($bold ? 1 : 0),
            ];
        }

        return $cells;
    }

    /** @param list<array{v: mixed, type: string, style: int}> $cells */
    protected function xlsxRow(int $line, array $cells): string
    {
        $out = '<row r="'.$line.'">';

        foreach ($cells as $index => $cell) {
            $ref = $this->columnLetter($index).$line;

            if ($cell['v'] === null || $cell['v'] === '') {
                continue;
            }

            if ($cell['type'] === 'number') {
                $out .= '<c r="'.$ref.'" s="'.$cell['style'].'"><v>'.(float) $cell['v'].'</v></c>';

                continue;
            }

            // Inline strings rather than a shared-strings table: a report is
            // written once and read once, so the deduplication a shared table
            // buys is not worth another XML part to keep in sync.
            $out .= '<c r="'.$ref.'" s="'.$cell['style'].'" t="inlineStr"><is><t xml:space="preserve">'
                .htmlspecialchars((string) $cell['v'], ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'</t></is></c>';
        }

        return $out.'</row>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    protected function columnLetter(int $index): string
    {
        $letter = '';

        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26) - 1;
        }

        return $letter;
    }
}
