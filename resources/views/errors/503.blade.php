{{--
    Service unavailable (#93, #160).

    The operator's own maintenance switch renders `maintenance.blade.php` with
    its own message. This one is the framework's `artisan down`, or a 503 from
    somewhere below us — same status, no message to show.
--}}
<x-errors.shell code="503" heading="Back shortly" tone="brand">
    <p>
        {{ $exception?->getMessage() ?: 'The service is briefly unavailable while something is being updated.' }}
    </p>
    <p>Nothing you saved has been lost. Try again in a few minutes.</p>
</x-errors.shell>
