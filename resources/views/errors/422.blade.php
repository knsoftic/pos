{{--
    Unprocessable (#93).

    ⚠️ THIS IS NOT A BREAKAGE, and the page must not say it is. A 422 here means
    the request was understood perfectly and refused on its merits: a draft that
    has already been received cannot be edited again, a sale that is not on hold
    cannot be resumed, a barcode already belongs to another product.

    Without this file those all fell through to Symfony's own page, which
    announces "Something is broken. Please let us know what you were doing" —
    over a rule the application is enforcing on purpose. That teaches shopkeepers
    to report correct behaviour as a fault, and to distrust the rest of the app.

    The abort message is shown when there is one, because whoever wrote
    `abort(422, 'Only a draft can be edited.')` had already said the useful part.
--}}
<x-errors.shell code="422" heading="That can't be done right now" tone="amber">
    @if (filled($exception?->getMessage()))
        <p><strong>{{ $exception->getMessage() }}</strong></p>
        <p>
            Nothing has changed, and nothing is broken — this is the system holding
            a rule rather than failing at one.
        </p>
    @else
        <p>
            The request made sense, but it cannot be carried out in the state
            things are in — a record has probably moved on since the page was
            loaded, or a value it needs is missing.
        </p>
        <p>
            Nothing has changed. Go back, reload the screen so it shows where
            things actually stand, and try again.
        </p>
    @endif

    <x-slot:actions>
        <a class="btn btn-primary" href="{{ landingUrl() }}">{{ landingLabel() }}</a>
    </x-slot:actions>
</x-errors.shell>
