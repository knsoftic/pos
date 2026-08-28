{{--
    The legal / support footer.

    The copyright range grows on its own — "© 2026" this year, "© 2026–2031"
    later — because a stale year in a footer is the smallest possible signal
    that nobody is maintaining the product.

    Contact lines are omitted entirely when the config value is empty, rather
    than rendering a label with nothing after it.
--}}
@props([
    'align' => 'center',   // center | between
    'muted' => false,      // for dark surfaces
    'version' => false,
])

@php
    $since = (int) config('brand.copyright_since');
    $now = (int) now()->format('Y');
    $years = $now > $since ? $since.'–'.$now : (string) $since;

    $textClass = $muted
        ? 'text-slate-500'
        : 'text-slate-500 dark:text-slate-400';

    $linkClass = $muted
        ? 'text-slate-400 hover:text-slate-200'
        : 'text-slate-600 hover:text-brand-700 dark:text-slate-300 dark:hover:text-brand-300';
@endphp

<div {{ $attributes->merge([
    'class' => 'flex flex-wrap items-center gap-x-3 gap-y-1 text-xs '.$textClass.' '.
        ($align === 'between' ? 'justify-between' : 'justify-center text-center'),
]) }}>
    <span>
        &copy; {{ $years }} <span class="font-medium">{{ config('brand.legal_name') }}</span>.
        All rights reserved.
    </span>

    <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
        @if (config('brand.website'))
            <a href="{{ config('brand.website') }}" target="_blank" rel="noopener noreferrer"
               class="{{ $linkClass }} transition-colors">{{ config('brand.website_label') }}</a>
        @endif

        @if (config('brand.support_email'))
            <a href="mailto:{{ config('brand.support_email') }}"
               class="{{ $linkClass }} transition-colors">{{ config('brand.support_email') }}</a>
        @endif

        @if (config('brand.support_phone'))
            <a href="tel:{{ preg_replace('/[^+0-9]/', '', config('brand.support_phone')) }}"
               class="{{ $linkClass }} transition-colors">{{ config('brand.support_phone') }}</a>
        @endif

        @if ($version)
            <span class="opacity-70">v{{ config('brand.version') }}</span>
        @endif
    </span>
</div>
