<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\RegistrationService;
use App\Support\MarketingContent;
use Illuminate\View\View;

/**
 * The marketing website (#106, #107).
 *
 * One controller, because every page here answers the same thing: show the
 * words, and offer the same call to action. The words live in
 * {@see MarketingContent}; the shape lives in two Blade files.
 */
class PageController extends Controller
{
    public function __construct(protected RegistrationService $registration) {}

    public function home(): View
    {
        return view('public.home', [
            'pillars' => MarketingContent::pillars(),
            'pages' => MarketingContent::pages(),
            'faqs' => array_slice(MarketingContent::faqs(), 0, 4),

            // The home page shows real plans or none at all. A pricing teaser
            // with invented numbers is the fastest way to lose somebody's trust
            // before they have even signed up.
            'plans' => Plan::query()->active()->public()->ordered()->with('prices')->get(),

            'canRegister' => $this->registration->isOpen(),
            'trialDays' => $this->registration->trialDays(),
        ]);
    }

    /** Features, POS, Inventory, Reports — one template, four sets of words. */
    public function page(string $slug): View
    {
        return view('public.page', [
            'slug' => $slug,
            'page' => MarketingContent::page($slug),
            'canRegister' => $this->registration->isOpen(),
            'trialDays' => $this->registration->trialDays(),
        ]);
    }

    public function faq(): View
    {
        return view('public.faq', [
            'faqs' => MarketingContent::faqs(),
            'canRegister' => $this->registration->isOpen(),
        ]);
    }

    public function contact(): View
    {
        return view('public.contact');
    }
}
