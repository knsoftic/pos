{{--
    Forbidden (#93).

    Reached when something bypasses PermissionDeniedException — which normally
    refuses IN PLACE rather than on a page of its own, because a cashier who
    lacks one permission has not lost the screen they were on.
--}}
<x-errors.shell code="403" heading="That one isn't yours to open" tone="amber">
    <p>
        You're signed in — this isn't a login problem. This particular action or
        screen just isn't in your role.
    </p>
    <p>
        If you need it, the business owner can add it to your role; nobody else
        can, and no amount of retrying will.
    </p>

    <x-slot:actions>
        <a class="btn btn-primary" href="{{ landingUrl() }}">{{ landingLabel() }}</a>
    </x-slot:actions>
</x-errors.shell>
