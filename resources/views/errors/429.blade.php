{{--
    Too many requests (#65, #100).

    Names the wait, because a limit with no stated end reads as a ban, and the
    natural response to a ban is to phone support rather than wait a minute.
--}}
@php
    $seconds = (int) ($exception?->getHeaders()['Retry-After'] ?? 0);

    if ($seconds <= 0) {
        $wait = 'a moment';
    } elseif ($seconds < 60) {
        $wait = $seconds.' seconds';
    } else {
        $minutes = (int) ceil($seconds / 60);
        $wait = $minutes.' '.\Illuminate\Support\Str::plural('minute', $minutes);
    }
@endphp
<x-errors.shell code="429" heading="Too many attempts" tone="amber">
    <p>
        That came in faster than we allow. Waiting {{ $wait }} and trying once
        more will work.
    </p>
    <p>
        If you weren't doing anything unusual, something on this device may be
        retrying in a loop — closing the tab and reopening it usually settles it.
    </p>

    <x-slot:actions>
        <a class="btn btn-primary" href="{{ landingUrl() }}">{{ landingLabel() }}</a>
    </x-slot:actions>
</x-errors.shell>
