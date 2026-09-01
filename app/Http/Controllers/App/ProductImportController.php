<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\ProductImportService;
use App\Support\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import and export of the catalogue (#150, #151).
 *
 * Exports STREAM. Building the whole CSV in memory first would work fine on the
 * demo data and fall over on the tenant who actually needs it, which is the
 * worst possible time to find out.
 */
class ProductImportController extends Controller
{
    public function __construct(protected ProductImportService $import) {}

    public function index(): View
    {
        return view('app.products.import', [
            'columns' => ProductImportService::COLUMNS,
        ]);
    }

    /** Download the catalogue (#151). */
    public function export(Request $request): StreamedResponse
    {
        // Cost is a permission, in a file exactly as on a screen (#52).
        $includeCost = $request->user()->can(PermissionRegistry::PRODUCTS_VIEW_COST);

        $filename = 'products-'.now()->format('Y-m-d').'.csv';

        return $this->streamCsv($filename, $this->import->exportRows($includeCost));
    }

    /** An empty file with the right headers, so nobody has to guess the format. */
    public function template(): StreamedResponse
    {
        return $this->streamCsv('product-import-template.csv', $this->import->templateRows());
    }

    /** Upload and apply (#150). */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            // `txt` is allowed because Windows renames CSVs on the way out of
            // some spreadsheet tools, and refusing it just confuses people.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.mimes' => 'Upload a CSV file.',
            'file.max' => 'That file is larger than 5 MB — split it up.',
        ]);

        $result = $this->import->import(
            $request->file('file'),
            $request->user()->can(PermissionRegistry::PRODUCTS_VIEW_COST),
        );

        if ($result['errors'] !== []) {
            return back()
                ->with('error', 'Nothing was imported — the file has problems.')
                ->with('import_errors', $result['errors']);
        }

        return redirect()
            ->route('app.products.index')
            ->with('success', sprintf(
                'Import finished: %d created, %d updated.',
                $result['created'],
                $result['updated'],
            ));
    }

    /**
     * @param  iterable<int, list<string>>  $rows
     */
    protected function streamCsv(string $filename, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            // Excel reads a plain UTF-8 CSV as Latin-1 and mangles every
            // accented character; the BOM is what tells it otherwise.
            fwrite($handle, "\u{FEFF}");

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
