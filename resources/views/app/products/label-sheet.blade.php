{{--
    The printable sheet (#27).

    Deliberately NOT inside the app layout: a print job wants no sidebar, no
    topbar and no dark mode. Black on white, because that is what a thermal or
    laser label printer actually puts on the paper.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Barcode labels · {{ config('brand.product') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <style>
        /* Self-contained: this page must print identically on a machine that
           never loaded the app's CSS bundle. */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 8mm;
            background: #fff;
            color: #000;
            font-family: Inter, ui-sans-serif, system-ui, sans-serif;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-bottom: 10mm;
            padding-bottom: 4mm;
            border-bottom: 1px solid #ddd;
        }

        .toolbar h1 { margin: 0; font-size: 15px; font-weight: 700; }
        .toolbar p { margin: 0; font-size: 12px; color: #666; }

        .btn {
            margin-left: auto;
            padding: 8px 14px;
            border: 0;
            border-radius: 8px;
            background: #1f4ded;
            color: #fff;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .sheet {
            display: flex;
            flex-wrap: wrap;
            gap: 3mm;
        }

        .label {
            width: {{ $labelWidth }}mm;
            padding: 2mm;
            border: 1px dashed #ccc;
            border-radius: 2px;
            text-align: center;
            /* A label split across two pages is a wasted label. */
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .label .name {
            margin: 0 0 1mm;
            font-size: 8pt;
            font-weight: 600;
            line-height: 1.2;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .label .price { margin: 1mm 0 0; font-size: 10pt; font-weight: 700; }
        .label .code { margin: 0.5mm 0 0; font-family: monospace; font-size: 7pt; }
        .label svg { width: 100%; height: auto; display: block; }

        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            /* The cutting guides are for the screen; on paper they waste ink. */
            .label { border-color: transparent; }
        }
    </style>
</head>
<body>

    <div class="toolbar">
        <div>
            <h1>{{ count($labels) }} {{ Str::plural('label', count($labels)) }}</h1>
            <p>{{ $labelWidth }}mm wide · check your printer's paper size, then print.</p>
        </div>
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>

    <div class="sheet">
        @foreach ($labels as $product)
            <div class="label">
                @if ($showName)
                    <p class="name">{{ $product->name }}</p>
                @endif

                @php $svg = \App\Support\Ean13::svg($product->barcode); @endphp

                @if ($svg !== '')
                    {!! $svg !!}
                @else
                    {{-- Not a valid EAN-13 (a hand-typed supplier code, say).
                         Printing bars that scan as something else would be worse
                         than printing none, so the number goes on plain. --}}
                    <p class="code">{{ $product->barcode }}</p>
                @endif

                @if ($showPrice)
                    {{-- The shop's OWN money: this label goes on the shop's own
                         shelf. It used to print `subscription.currency_symbol`,
                         which is what we bill the shop in. --}}
                    <p class="price">{{ money($product->selling_price, true) }}</p>
                @endif
            </div>
        @endforeach
    </div>

</body>
</html>
