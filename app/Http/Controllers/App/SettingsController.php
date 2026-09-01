<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\BusinessProfileRequest;
use App\Http\Requests\App\SettingsRequest;
use App\Models\TaxRate;
use App\Services\FeatureService;
use App\Services\SettingsService;
use App\Support\FeatureRegistry;
use App\Support\SettingRegistry;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The shop's own settings (#57–#60, #153–#157).
 *
 * ================= TWO KINDS OF THING, ONE SCREEN =================
 * "General" edits the BUSINESS RECORD — name, address, logo, timezone. Those
 * are columns on `businesses`, not settings, because they are facts about the
 * shop rather than choices about how it operates, and giving them a second home
 * in a settings table would leave no answer to which one wins.
 *
 * Every other tab edits SETTINGS: overrides on top of the shipped config, held
 * by {@see SettingsService}.
 *
 * ================= WHY GROUPS AND NOT ONE LONG FORM =================
 * Forty knobs on one page is a page nobody reads and a save button nobody
 * trusts. Each tab posts only its own group, so a shopkeeper changing the
 * receipt footer cannot accidentally rewrite their tax settings.
 */
class SettingsController extends Controller
{
    public function __construct(
        protected SettingsService $settings,
        protected FeatureService $features,
        protected TenantContext $tenant,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('app.settings.show', 'general');
    }

    public function show(string $group): View
    {
        $business = $this->tenant->business();

        abort_unless(in_array($group, $this->groups(), true), 404, 'No such settings page.');

        return view('app.settings.show', [
            'group' => $group,
            'groups' => $this->groups(),
            'labels' => ['general' => 'Business', 'taxes' => 'Tax rates'] + SettingRegistry::groupLabels(),
            'descriptions' => [
                'general' => 'Who the shop is — the details that appear on receipts and reports.',
                'taxes' => 'The rates this shop charges, and which one a new product starts with.',
            ] + SettingRegistry::groupDescriptions(),
            'business' => $business,
            'settings' => $this->settings->all(),
            'customised' => $this->settings->customised(),
            // A setting whose plan feature is off is not shown at all: an
            // inert switch teaches people that switches do not work.
            'definitions' => $group === 'general' || $group === 'taxes'
                ? []
                : $this->availableIn($group),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'taxRates' => $group === 'taxes' ? TaxRate::query()->ordered()->get() : collect(),
            'canManageTax' => $this->features->enabled(FeatureRegistry::SALES_TAX),
        ]);
    }

    /** The business record itself. */
    public function updateBusiness(BusinessProfileRequest $request): RedirectResponse
    {
        $business = $this->tenant->business();
        $data = $request->businessAttributes();

        if ($request->hasFile('logo')) {
            $previous = $business->logo_path;

            $data['logo_path'] = $request->file('logo')->store(
                config('uploads.products.path').'/logos',
                config('uploads.products.disk'),
            );

            // Replaced, never accumulated — one shop, one logo.
            if ($previous) {
                Storage::disk(config('uploads.products.disk'))->delete($previous);
            }
        } elseif ($request->boolean('remove_logo') && $business->logo_path) {
            Storage::disk(config('uploads.products.disk'))->delete($business->logo_path);
            $data['logo_path'] = null;
        }

        $business->fill($data)->save();

        return back()->with('success', 'Business details saved.');
    }

    public function update(SettingsRequest $request, string $group): RedirectResponse
    {
        abort_unless(in_array($group, array_keys(SettingRegistry::groupLabels()), true), 404, 'No such settings page.');

        $this->settings->put($request->settingsFor($this->availableIn($group)));

        return back()->with('success', SettingRegistry::groupLabels()[$group].' saved.');
    }

    /** Put a whole group back to what the software ships with. */
    public function reset(string $group): RedirectResponse
    {
        abort_unless(in_array($group, array_keys(SettingRegistry::groupLabels()), true), 404, 'No such settings page.');

        foreach (array_keys($this->availableIn($group)) as $key) {
            $this->settings->forget($key);
        }

        return back()->with('success', SettingRegistry::groupLabels()[$group].' reset to the defaults.');
    }

    // ------------------------------------------------------------- internals

    /** @return list<string> */
    protected function groups(): array
    {
        return array_merge(['general'], array_keys(SettingRegistry::groupLabels()), ['taxes']);
    }

    /**
     * The settings in a group that this plan actually includes.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function availableIn(string $group): array
    {
        return array_filter(
            SettingRegistry::group($group),
            fn (array $definition) => ! isset($definition['feature']) || $this->features->enabled($definition['feature']),
        );
    }
}
