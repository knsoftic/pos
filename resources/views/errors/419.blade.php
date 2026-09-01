{{--
    Page expired — a missing or stale CSRF token (#93, #100).

    THE ONE PEOPLE ACTUALLY HIT. Almost never an attack: it is a form that sat
    open past the session lifetime, and the person on the other side of it has
    just been told "Page Expired" by a framework default and has no idea whether
    their work is gone. Most of the time it is not, and saying so is the entire
    job of this page.
--}}
<x-errors.shell code="419" heading="Your session expired while this page was open" tone="amber">
    <p>
        For safety the app signs you out after a spell of inactivity, and this
        form was filled in before that happened. It wasn't submitted.
    </p>
    <p>
        <strong>Sign in again and the page should still have your work</strong> —
        a POS cart is held in this browser, not on the server, so it survives
        this. Anything typed into a form will need entering once more.
    </p>

    <x-slot:actions>
        <a class="btn btn-primary" href="{{ route('login') }}">Sign in again</a>
        <a class="btn btn-ghost" href="{{ url()->previous() }}">Back to the page</a>
    </x-slot:actions>
</x-errors.shell>
