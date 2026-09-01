<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RegistrationRequest;
use App\Services\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Self-service sign-up (#109).
 *
 * ================= THE SWITCH IS CHECKED TWICE =================
 * Once to decide whether to draw the form, and again before anything is
 * written. The first is a courtesy; the second is the guard, because a form
 * that was open when somebody loaded it can be closed by the time they submit,
 * and a bookmarked URL never asked permission in the first place (#110).
 */
class RegistrationController extends Controller
{
    public function __construct(protected RegistrationService $registration) {}

    public function create(): View|RedirectResponse
    {
        if (! $this->registration->isOpen()) {
            return redirect()
                ->route('pricing')
                ->with('error', 'Sign-ups are closed at the moment. Get in touch and we will set you up.');
        }

        $plan = $this->registration->trialPlan();

        return view('auth.register', [
            'plan' => $plan,
            'trialDays' => $this->registration->trialDays(),
        ]);
    }

    public function store(RegistrationRequest $request): RedirectResponse
    {
        $owner = $this->registration->register($request->registrationAttributes());

        // Signed straight in: asking somebody to log in with the password they
        // typed ten seconds ago is a step that exists only for the software's
        // convenience.
        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return redirect()
            ->route('app.dashboard')
            ->with('success', 'Welcome — your shop is ready. Add a product to get going.');
    }
}
