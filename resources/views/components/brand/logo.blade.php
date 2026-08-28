{{--
    Mark + wordmark. The wordmark is HTML rather than SVG on purpose: it then
    inherits the page's text colour, so one component works on a white card, a
    dark sidebar and in both themes without a second asset.

    Sizes are named rather than free-form so the logo cannot drift a few pixels
    between screens — that inconsistency is exactly what makes a brand look
    homemade.
--}}
@props([
    'size' => 'md',        // sm | md | lg
    'wordmark' => true,
    'tagline' => false,
    'href' => null,
    'inverted' => false,   // for dark surfaces that are dark in BOTH themes
])

@php
    $sizes = [
        'sm' => ['mark' => 'h-8 w-8', 'rounded' => 'rounded-lg', 'name' => 'text-sm', 'tag' => 'text-[10px]'],
        'md' => ['mark' => 'h-10 w-10', 'rounded' => 'rounded-xl', 'name' => 'text-base', 'tag' => 'text-xs'],
        'lg' => ['mark' => 'h-14 w-14', 'rounded' => 'rounded-2xl', 'name' => 'text-xl', 'tag' => 'text-sm'],
    ];

    $s = $sizes[$size] ?? $sizes['md'];

    // "KN" is the memorable half, so it carries the weight; "Softic" sits back.
    $strongClass = $inverted
        ? 'text-white'
        : 'text-slate-900 dark:text-white';

    $softClass = $inverted
        ? 'text-slate-300'
        : 'text-slate-500 dark:text-slate-400';

    $tag = config('brand.tagline');
@endphp

<{{ $href ? 'a' : 'span' }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>

    <x-brand.mark :class="$s['mark']" :rounded="$s['rounded']" />

    @if ($wordmark)
        <span class="flex min-w-0 flex-col leading-tight">
            <span class="truncate font-bold tracking-tight {{ $s['name'] }} {{ $strongClass }}">
                KN<span class="font-medium {{ $softClass }}">&nbsp;Softic</span>
            </span>

            @if ($tagline && $tag)
                <span class="truncate {{ $s['tag'] }} {{ $softClass }}">{{ $tag }}</span>
            @endif
        </span>
    @endif
</{{ $href ? 'a' : 'span' }}>
