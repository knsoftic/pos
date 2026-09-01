<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\TaxRateRequest;
use App\Models\TaxRate;
use App\Services\TaxRateService;
use Illuminate\Http\RedirectResponse;

/**
 * The tax rates a shop charges (#59).
 *
 * Lives under Settings because that is where a shopkeeper looks for it, and it
 * is the only list here that is a table rather than a knob.
 */
class TaxRateController extends Controller
{
    public function __construct(protected TaxRateService $rates) {}

    public function store(TaxRateRequest $request): RedirectResponse
    {
        $rate = $this->rates->create($request->rateAttributes());

        return back()->with('success', "\"{$rate->label()}\" added.");
    }

    public function update(TaxRateRequest $request, TaxRate $taxRate): RedirectResponse
    {
        $this->rates->update($taxRate, $request->rateAttributes());

        return back()->with('success', "\"{$taxRate->label()}\" updated.");
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        $label = $taxRate->label();

        $this->rates->delete($taxRate);

        return back()->with('success', "\"{$label}\" removed.");
    }
}
