{{--
    Not found (#93).

    ⚠️ This is ALSO what another shop's record looks like, and that is on
    purpose: route model binding is tenant-scoped, so business B asking for
    business A's invoice gets nothing rather than a 403. A 403 would confirm
    the record exists, which is half of what the asker wanted to know (#117).
--}}
<x-errors.shell code="404" heading="There's nothing here" tone="slate">
    <p>
        The page you asked for doesn't exist — or it belongs to someone else's
        shop, which from here looks exactly the same. That's deliberate.
    </p>
    <p>
        If you got here from a link inside the app, the record may have been
        renamed or archived since the link was made.
    </p>

    <x-slot:actions>
        <a class="btn btn-primary" href="{{ landingUrl() }}">{{ landingLabel() }}</a>
    </x-slot:actions>
</x-errors.shell>
