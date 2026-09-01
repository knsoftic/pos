<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\FeatureRegistry;
use App\Support\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The reports module (#54, #55, #56).
 *
 * ================= WHY ONE CONTROLLER FOR THIRTY REPORTS =================
 * Every report answers the same three questions in the same order — which
 * report, over what, in what format — so there is one route for the catalogue,
 * one for a report and one for an export. The differences between reports live
 * in {@see ReportRegistry} and {@see ReportService}, where they can be compared
 * side by side.
 *
 * ================= THE GATES ARE CHECKED PER REPORT =================
 * The route itself only requires `reports.view`, because that is the weakest
 * thing any report needs. Each report then declares its own feature and its own
 * permission, and both are checked HERE against the one being asked for (#187).
 * Without that, a route-level gate would have to be the strictest of thirty
 * reports and nobody would see anything.
 */
class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reports,
        protected ReportExporter $exporter,
        protected FeatureService $features,
    ) {}

    /** The catalogue: what this shop's plan and this person's role allow. */
    public function index(Request $request): View
    {
        return view('app.reports.index', [
            'groups' => $this->available($request),
            'groupLabels' => ReportRegistry::groupLabels(),
        ]);
    }

    public function show(Request $request, string $report): View
    {
        $definition = $this->authorizeReport($request, $report);

        $built = $this->reports->build($report, $request->query());

        return view('app.reports.show', [
            'report' => $built,
            'definition' => $definition,
            'filters' => $this->filterOptions($definition['filters']),
            'query' => $request->query(),
            'formats' => $this->exportFormats(),
        ]);
    }

    public function export(Request $request, string $report, string $format)
    {
        $this->authorizeReport($request, $report);

        return $this->exporter->download(
            $this->reports->build($report, $request->query()),
            $format,
        );
    }

    // ------------------------------------------------------------- internals

    /**
     * Both gates, for THIS report.
     *
     * @return array<string, mixed>
     */
    protected function authorizeReport(Request $request, string $report): array
    {
        $definition = ReportRegistry::definition($report);

        // The plan gate throws a FeatureUnavailableException, which sends the
        // owner to billing. The permission gate refuses in place — see
        // PermissionDeniedException for why those are different roads.
        $this->features->authorize($definition['feature']);

        abort_unless(
            $request->user()?->can($definition['permission']),
            403,
            'That report is outside what your role can see.',
        );

        return $definition;
    }

    /**
     * The catalogue, already filtered to what this person can actually open.
     *
     * A greyed-out list of reports the shop cannot have would be an advert, not
     * a menu; the billing page is where upgrades are sold.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function available(Request $request): array
    {
        $user = $request->user();
        $enabled = $this->features->all();

        $groups = [];

        foreach (ReportRegistry::all() as $key => $definition) {
            if (! ($enabled[$definition['feature']] ?? false)) {
                continue;
            }

            if (! $user?->can($definition['permission'])) {
                continue;
            }

            $groups[$definition['group']][$key] = $definition;
        }

        return $groups;
    }

    /**
     * Only the dropdowns this report actually uses (#55).
     *
     * @param  list<string>  $wanted
     * @return array<string, mixed>
     */
    protected function filterOptions(array $wanted): array
    {
        $options = [];

        if (in_array('branch', $wanted, true)) {
            $options['branches'] = Branch::query()->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('employee', $wanted, true)) {
            $options['employees'] = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('customer', $wanted, true)) {
            $options['customers'] = Customer::query()->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('supplier', $wanted, true)) {
            $options['suppliers'] = Supplier::query()->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('category', $wanted, true)) {
            $options['categories'] = Category::query()->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('product', $wanted, true)) {
            // Capped deliberately: a shop with 20,000 products would otherwise
            // ship all of them into a <select> on every report load.
            $options['products'] = Product::query()->orderBy('name')->limit(500)->get(['id', 'name', 'sku']);
        }

        return $options;
    }

    /**
     * Which download buttons to draw, by plan (#56).
     *
     * @return array<string, string>
     */
    protected function exportFormats(): array
    {
        $formats = ['csv' => 'CSV'];

        if ($this->features->enabled(FeatureRegistry::REPORTS_EXPORT_EXCEL)) {
            $formats['xlsx'] = 'Excel';
        }

        if ($this->features->enabled(FeatureRegistry::REPORTS_EXPORT_PDF)) {
            $formats['pdf'] = 'PDF';
        }

        return $formats;
    }
}
