{{--
    The KN Softic mark.

    Drawn as geometry, never as text: a <text> element would depend on a font
    being loaded, and the mark has to render identically in a favicon, an email,
    a printed receipt and a page that failed to reach the font CDN.

    The bar and the chevron together read as a K; the gradient tile carries the
    brand blue already defined in resources/css/app.css.

    `gradientId` exists because two of these on one page would otherwise share a
    <defs> id, and the second would silently take the first one's fill.
--}}
@props([
    'class' => 'h-9 w-9',
    'rounded' => 'rounded-xl',
])

@php
    $gradientId = 'kn-mark-'.Str::random(6);

    // A white-label operator's own mark, if they have uploaded one (#111).
    $uploaded = config('brand.logo_path');
@endphp

@if ($uploaded)
    <span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center '.$class]) }}>
        <img src="{{ Storage::disk(config('uploads.products.disk'))->url($uploaded) }}"
             alt="{{ config('brand.name') }}"
             class="h-full w-auto max-w-full object-contain {{ $rounded }}" />
    </span>
@else
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center '.$class]) }}>
    <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg"
         class="h-full w-full {{ $rounded }} shadow-sm shadow-brand-900/20"
         role="img" aria-label="{{ config('brand.name') }}">
        <defs>
            <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="48" y2="48" gradientUnits="userSpaceOnUse">
                <stop stop-color="#3366ff" />
                <stop offset="1" stop-color="#1a3fd0" />
            </linearGradient>
        </defs>

        <rect width="48" height="48" rx="12" fill="url(#{{ $gradientId }})" />

        {{-- K: upright bar + chevron --}}
        <rect x="12.5" y="13" width="4.6" height="22" rx="2.3" fill="white" />
        <path d="M22 13.8 L32.4 24 L22 34.2"
              stroke="white" stroke-width="4.6" stroke-linecap="round" stroke-linejoin="round" fill="none" />

        {{-- Accent: the dot that turns a letter into a mark --}}
        <circle cx="36.4" cy="15.6" r="2.4" fill="white" fill-opacity="0.55" />
    </svg>
</span>
@endif
