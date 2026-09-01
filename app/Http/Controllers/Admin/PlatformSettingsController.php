<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BrandLogoRequest;
use App\Http\Requests\Admin\PlatformSettingsRequest;
use App\Services\PlatformSettingsService;
use App\Support\PlatformSettingRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The operator's own settings (#110, #111, #160).
 *
 * Mirrors the tenant settings screen deliberately — same tabs, same "back to
 * defaults", same "changed" markers — because it is the same idea one level up
 * and two different shapes would be two things to learn.
 */
class PlatformSettingsController extends Controller
{
    public function __construct(protected PlatformSettingsService $platform) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', 'branding');
    }

    public function show(string $group): View
    {
        abort_unless(array_key_exists($group, PlatformSettingRegistry::groupLabels()), 404, 'No such settings page.');

        return view('admin.settings.show', [
            'group' => $group,
            'labels' => PlatformSettingRegistry::groupLabels(),
            'descriptions' => PlatformSettingRegistry::groupDescriptions(),
            // Hidden entries (the logo) go through the same store and the same
            // "back to defaults", but a file is not a text box so the generic
            // form skips them and the branding tab draws its own control.
            'definitions' => array_filter(
                PlatformSettingRegistry::group($group),
                fn (array $definition) => ! ($definition['hidden'] ?? false),
            ),
            'settings' => $this->platform->all(),
            'customised' => $this->platform->customised(),
            'logo' => $this->platform->get('brand.logo_path'),
        ]);
    }

    /**
     * The operator's own mark (#111).
     *
     * Replaced, never accumulated. Removing it falls back to the drawn
     * <x-brand.mark>, which is geometry rather than an image and therefore
     * renders in places a file never reaches.
     */
    public function updateLogo(BrandLogoRequest $request): RedirectResponse
    {
        $disk = config('uploads.products.disk');
        $previous = $this->platform->get('brand.logo_path');

        if ($request->boolean('remove_logo')) {
            $this->platform->put(['brand.logo_path' => null]);

            if ($previous) {
                Storage::disk($disk)->delete($previous);
            }

            return back()->with('success', 'Logo removed — back to the built-in mark.');
        }

        $path = $request->file('logo')->store(config('uploads.products.path').'/brand', $disk);

        $this->platform->put(['brand.logo_path' => $path]);

        if ($previous && $previous !== $path) {
            Storage::disk($disk)->delete($previous);
        }

        return back()->with('success', 'Logo updated.');
    }

    public function update(PlatformSettingsRequest $request, string $group): RedirectResponse
    {
        abort_unless(array_key_exists($group, PlatformSettingRegistry::groupLabels()), 404, 'No such settings page.');

        $this->platform->put($request->settingsFor(PlatformSettingRegistry::group($group)));

        return back()->with('success', PlatformSettingRegistry::groupLabels()[$group].' saved.');
    }

    public function reset(string $group): RedirectResponse
    {
        abort_unless(array_key_exists($group, PlatformSettingRegistry::groupLabels()), 404, 'No such settings page.');

        foreach (array_keys(PlatformSettingRegistry::group($group)) as $key) {
            $this->platform->forget($key);
        }

        return back()->with('success', PlatformSettingRegistry::groupLabels()[$group].' reset to the defaults.');
    }
}
