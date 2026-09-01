{{--
    Shown to shops while the platform is closed (#160).

    Standalone HTML on purpose: this page has to render when something is being
    replaced underneath it, so it depends on no compiled stylesheet and no
    layout. It is also served with a 503 — a maintenance page answering 200
    gets cached and indexed as the site's content and outlives the outage.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Back shortly · {{ config('brand.product') }}</title>
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
            max-width: 460px;
            width: 100%;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 36px 32px;
            text-align: center;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .06);
        }
        .mark {
            width: 56px; height: 56px; margin: 0 auto 18px;
            border-radius: 15px;
            background: linear-gradient(135deg, #3366ff, #1a3fd0);
            display: flex; align-items: center; justify-content: center;
        }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { margin: 0; color: #475569; font-size: 14px; line-height: 1.6; }
        .foot { margin-top: 22px; font-size: 12px; color: #94a3b8; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #f1f5f9; }
            .card { background: #1e293b; border-color: #334155; }
            p { color: #cbd5e1; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="mark">
            <svg viewBox="0 0 48 48" width="30" height="30" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="12.5" y="13" width="4.6" height="22" rx="2.3" fill="white" />
                <path d="M22 13.8 L32.4 24 L22 34.2" stroke="white" stroke-width="4.6"
                      stroke-linecap="round" stroke-linejoin="round" fill="none" />
            </svg>
        </div>

        <h1>Back shortly</h1>
        <p>{{ $message }}</p>

        <p class="foot">
            {{ config('brand.name') }}
            @if (config('brand.support_email'))
                &middot; {{ config('brand.support_email') }}
            @endif
        </p>
    </div>
</body>
</html>
