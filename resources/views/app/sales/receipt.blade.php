{{--
    The receipt (#23, #144, #145).

    ONE TEMPLATE FOR ALL THREE WIDTHS. 58mm and 80mm are thermal rolls — the
    difference between them is how much room there is, not what the receipt says
    — and A4 is what goes in an envelope. Sharing the template means a change to
    what a receipt SAYS cannot land on one width and quietly miss another.

    Deliberately outside the app layout, like the barcode sheet: a print job
    wants no sidebar, no topbar and no dark mode. Black on white, because that is
    what a thermal printer actually puts on paper.
--}}
@php
    $isRoll = $width !== 'a4';
    $pageWidth = match ($width) { '58mm' => '58mm', '80mm' => '80mm', default => '210mm' };
    $business = $sale->business ?? auth('web')->user()?->business;
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->invoice_no }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <style>
        /* Self-contained: this has to print identically on a machine that never
           loaded the app's CSS bundle. */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 12px;
            background: #f1f5f9;
            color: #000;
            font-family: {{ $isRoll ? "'Courier New', ui-monospace, monospace" : 'Inter, ui-sans-serif, system-ui, sans-serif' }};
            font-size: {{ $width === '58mm' ? '11px' : ($isRoll ? '12px' : '13px') }};
            line-height: 1.45;
        }

        .toolbar {
            max-width: {{ $pageWidth }};
            margin: 0 auto 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #0f172a;
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-primary { border-color: #1f4ded; background: #1f4ded; color: #fff; }

        .sheet {
            width: {{ $pageWidth }};
            margin: 0 auto;
            padding: {{ $isRoll ? '10px' : '24px' }};
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,.15);
        }

        .center { text-align: center; }
        .right { text-align: right; }
        .muted { color: #475569; }
        .bold { font-weight: 700; }
        .big { font-size: {{ $isRoll ? '15px' : '20px' }}; }

        .rule { border: 0; border-top: 1px dashed #94a3b8; margin: 8px 0; }
        .rule-solid { border: 0; border-top: 2px solid #0f172a; margin: 8px 0; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: {{ $isRoll ? '10px' : '11px' }}; text-transform: uppercase; letter-spacing: .04em; color: #475569; padding-bottom: 4px; }
        td { padding: 3px 0; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

        .totals td { padding: 2px 0; }
        .totals .grand td { padding-top: 6px; font-size: {{ $isRoll ? '15px' : '18px' }}; font-weight: 700; }

        .voided {
            margin: 8px 0;
            padding: 6px;
            border: 2px solid #b91c1c;
            color: #b91c1c;
            font-weight: 700;
            text-align: center;
            letter-spacing: .1em;
        }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; box-shadow: none; padding: {{ $isRoll ? '0' : '12mm' }}; }
            @page { size: {{ $isRoll ? $pageWidth.' auto' : 'A4' }}; margin: {{ $isRoll ? '2mm' : '12mm' }}; }
        }
    </style>
</head>
<body>

    {{-- Print UX (#145): the three things wanted straight after a sale. --}}
    <div class="toolbar">
        <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
        <a class="btn" href="{{ route('app.pos.index') }}">New sale</a>
        <a class="btn" href="{{ route('app.sales.show', $sale) }}">View sale</a>
    </div>

    <div class="toolbar">
        {{-- Reprinting on another width is a reprint, and is counted (#143). --}}
        @foreach (['58mm' => '58mm', '80mm' => '80mm', 'a4' => 'A4'] as $value => $label)
            <a class="btn" href="{{ route('app.sales.receipt', [$sale, 'width' => $value, 'reprint' => 1]) }}"
               style="{{ $width === $value ? 'border-color:#1f4ded;color:#1f4ded' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="sheet">

        {{-- ─────────────────────────── who ─────────────────────────── --}}
        <div class="center">
            @if ($showLogo)
                <img src="{{ Storage::disk(config('uploads.products.disk'))->url($business->logo_path) }}"
                     alt="{{ $business->name }}"
                     style="max-height:56px; max-width:70%; margin:0 auto 6px; display:block" />
            @endif

            @if ($header !== '')
                <p class="muted" style="margin:0 0 2px; white-space:pre-line">{{ $header }}</p>
            @endif

            <p class="bold big" style="margin:0">{{ $business?->name ?? config('brand.product') }}</p>

            @if ($taxNumber !== '')
                <p class="muted" style="margin:2px 0 0">Tax No: {{ $taxNumber }}</p>
            @endif
            @if ($sale->branch)
                <p class="muted" style="margin:2px 0 0">{{ $sale->branch->name }}</p>
                @if ($sale->branch->address)
                    <p class="muted" style="margin:0">{{ $sale->branch->address }}</p>
                @endif
                @if ($sale->branch->phone)
                    <p class="muted" style="margin:0">{{ $sale->branch->phone }}</p>
                @endif
            @endif
        </div>

        <hr class="rule">

        @if ($sale->status === \App\Enums\SaleStatus::Voided)
            <p class="voided">VOIDED</p>
        @endif

        {{-- ─────────────────────────── what ────────────────────────── --}}
        <table>
            <tr>
                <td class="muted">Invoice</td>
                <td class="right bold">{{ $sale->invoice_no }}</td>
            </tr>
            <tr>
                <td class="muted">Date</td>
                <td class="right">{{ localDateTime($sale->sold_at) }}</td>
            </tr>
            <tr>
                <td class="muted">Customer</td>
                <td class="right">{{ $sale->customerName() }}</td>
            </tr>
            @if ($sale->seller)
                <tr>
                    <td class="muted">Served by</td>
                    <td class="right">{{ $sale->seller->name }}</td>
                </tr>
            @endif
        </table>

        <hr class="rule">

        {{-- ─────────────────────────── lines ───────────────────────── --}}
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num">Price</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sale->items as $item)
                    <tr>
                        <td>
                            {{ $item->description }}
                            @if ((float) $item->discount_amount > 0)
                                <br><span class="muted" style="font-size:.9em">
                                    less {{ money($item->discount_amount) }} discount
                                </span>
                            @endif
                        </td>
                        <td class="num">{{ qty($item->quantity) }}</td>
                        <td class="num">{{ money($item->unit_price) }}</td>
                        <td class="num">{{ money($item->net()) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="rule-solid">

        {{-- ─────────────────────────── money ───────────────────────── --}}
        <table class="totals">
            <tr>
                <td class="muted">Subtotal</td>
                <td class="num">{{ money($sale->subtotal) }}</td>
            </tr>

            @if ((float) $sale->discount_total > 0)
                <tr>
                    <td class="muted">Discount</td>
                    <td class="num">−{{ money($sale->discount_total) }}</td>
                </tr>
            @endif

            @if ($showTax && (float) $sale->tax_total > 0)
                <tr>
                    <td class="muted">Tax</td>
                    <td class="num">{{ money($sale->tax_total) }}</td>
                </tr>
            @endif

            @if ((float) $sale->rounding != 0)
                {{-- Shown rather than buried, so the receipt adds up on paper. --}}
                <tr>
                    <td class="muted">Rounding</td>
                    <td class="num">{{ (float) $sale->rounding > 0 ? '+' : '−' }}{{ money(abs((float) $sale->rounding)) }}</td>
                </tr>
            @endif

            <tr class="grand">
                <td>TOTAL</td>
                <td class="num">{{ money($sale->total, true) }}</td>
            </tr>
        </table>

        <hr class="rule">

        {{-- ─────────────────────── how it was paid ─────────────────── --}}
        <table class="totals">
            @foreach ($sale->payments as $payment)
                <tr>
                    <td class="muted">
                        {{ $payment->label() }}
                        @if ($payment->reference)
                            <span style="font-size:.9em">· {{ $payment->reference }}</span>
                        @endif
                    </td>
                    <td class="num">{{ money($payment->amount) }}</td>
                </tr>
            @endforeach

            @if ((float) $sale->change_given > 0)
                <tr>
                    <td class="muted">Change</td>
                    <td class="num">{{ money($sale->change_given) }}</td>
                </tr>
            @endif

            @if ((float) $sale->due_amount > 0)
                <tr>
                    <td class="bold">On account</td>
                    <td class="num bold">{{ money($sale->due_amount) }}</td>
                </tr>
            @endif
        </table>

        {{-- ──────────────────────────── the QR ─────────────────────── --}}
        @if ($qr)
            <hr class="rule">
            <div class="center" style="margin:6px 0">
                {!! $qr !!}
                <p class="muted" style="margin:3px 0 0; font-size:.8em">Scan to check this receipt</p>
            </div>
        @endif

        {{-- ─────────────────────────── footer (#144) ───────────────── --}}
        @if ($footer !== '')
            <hr class="rule">
            <p class="center muted" style="margin:0; white-space:pre-line">{{ $footer }}</p>
        @endif

        @if ($sale->print_count > 0)
            {{-- An honest receipt says it is a copy. Two identical-looking
                 invoices in circulation is exactly what #143 guards against. --}}
            <p class="center muted" style="margin-top:8px; font-size:.9em">
                REPRINT · copy {{ $sale->print_count + 1 }}
            </p>
        @endif

        <p class="center muted" style="margin-top:10px; font-size:.85em">
            {{ config('brand.name') }} POS
        </p>
    </div>

    @if ($autoPrint)
        {{-- #145: the till can hand straight off to the printer. Deliberately
             opt-in — a shop that prints only on request should not have a print
             dialog thrown at it after every sale. --}}
        <script>window.addEventListener('load', () => window.print());</script>
    @endif

</body>
</html>
