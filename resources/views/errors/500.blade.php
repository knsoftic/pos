{{--
    Something broke at our end (#93, #94).

    THE RULE THIS PAGE ENFORCES: in production a shopkeeper is never shown a
    class name, a file path, a SQL fragment or a stack trace. Not because it
    embarrasses us, but because each of those tells an attacker which framework,
    which database and which version they are up against, and none of it helps
    the person reading it.

    What DOES help them is the reference. It is written into the security log
    alongside the real exception, so a six-character code read down a phone line
    finds the exact stack trace — and the shopkeeper never has to describe a 500.
--}}
<x-errors.shell
    code="500"
    heading="Something went wrong at our end"
    tone="rose"
    :reference="app(\App\Services\SecurityLogger::class)->reference()">

    <p>
        This one is ours, not yours. The request failed before it finished, and
        it has been logged for us to look at.
    </p>
    <p>
        <strong>If you were saving something, assume it did not save</strong> and
        check before doing it again — a sale, a payment or a stock movement is
        all-or-nothing here, so a failure leaves nothing half-written.
    </p>

    <x-slot:actions>
        <a class="btn btn-primary" href="{{ landingUrl() }}">{{ landingLabel() }}</a>
    </x-slot:actions>
</x-errors.shell>
