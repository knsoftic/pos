@props([
    'code',
    'heading',
    'tone' => 'slate',
    'reference' => null,
])

{{--
    The frame every error page sits in (#93).

    Standalone HTML, exactly like the maintenance page and for the same reason:
    an error page that needs the compiled stylesheet cannot render when the
    thing that broke IS the asset pipeline. This one depends on nothing but the
    response body.

    The tone is a colour, not a mood — a 404 is not a disaster and should not be
    painted like one, while a 500 should not be painted like a shrug.
--}}
@php
    $tones = [
        'slate' => ['#64748b', '#f1f5f9', '#334155'],
        'amber' => ['#d97706', '#fef3c7', '#92400e'],
        'rose' => ['#e11d48', '#ffe4e6', '#9f1239'],
        'brand' => ['#3366ff', '#e0e7ff', '#1e3a8a'],
    ];

    [$accent, $wash, $ink] = $tones[$tone] ?? $tones['slate'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $heading }} · {{ config('brand.product') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }
        .card {
            max-width: 520px;
            width: 100%;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 36px 32px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .06);
        }
        .code {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            padding: 5px 10px;
            border-radius: 999px;
            background: {{ $wash }};
            color: {{ $ink }};
            margin-bottom: 16px;
        }
        h1 { font-size: 22px; margin: 0 0 10px; line-height: 1.3; }
        p { margin: 0 0 12px; color: #475569; font-size: 14.5px; line-height: 1.65; }
        p:last-of-type { margin-bottom: 0; }
        .actions { margin-top: 26px; display: flex; flex-wrap: wrap; gap: 10px; }
        a.btn, button.btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font: inherit;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 11px;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .btn-primary { background: {{ $accent }}; color: #fff; }
        .btn-ghost { background: transparent; color: #475569; border-color: #e2e8f0; }
        .ref {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #e2e8f0;
            font-size: 12.5px;
            color: #94a3b8;
        }
        .ref code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            background: #f1f5f9;
            padding: 2px 7px;
            border-radius: 6px;
            letter-spacing: .06em;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #f1f5f9; }
            .card { background: #1e293b; border-color: #334155; }
            p { color: #cbd5e1; }
            .btn-ghost { color: #cbd5e1; border-color: #475569; }
            .ref { border-color: #334155; }
            .ref code { color: #e2e8f0; background: #0f172a; }
        }
    </style>
</head>
<body>
    <div class="card">
        <span class="code">{{ $code }}</span>

        <h1>{{ $heading }}</h1>

        {{ $slot }}

        <div class="actions">
            {{ $actions ?? '' }}
        </div>

        @if ($reference)
            <p class="ref">
                If you need to report this, quote <code>{{ $reference }}</code> —
                it points support straight at what happened.
            </p>
        @endif
    </div>
</body>
</html>
