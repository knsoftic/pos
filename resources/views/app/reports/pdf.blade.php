{{--
    The PDF template (#56).

    ⚠️ Deliberately plain HTML with inline styles and no Tailwind: dompdf reads
    a small, old subset of CSS, and the app's stylesheet uses grid, custom
    properties and modern colour functions — none of which it understands. A
    "shared" stylesheet here would render as an unstyled column of text.

    It is also its own layout rather than the app one, because a PDF has no
    sidebar, no dark mode and no navigation to inherit.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['name'] }}</title>
    <style>
        @page { margin: 18mm 12mm 16mm 12mm; }

        body {
            font-family: DejaVu Sans, sans-serif;   /* ships with dompdf; has the currency and dash glyphs */
            font-size: 9px;
            color: #0f172a;
            margin: 0;
        }

        .head { border-bottom: 1.5px solid #1f4ded; padding-bottom: 8px; margin-bottom: 12px; }
        .brand { font-size: 15px; font-weight: bold; color: #1f4ded; }
        .shop { font-size: 10px; color: #475569; margin-top: 1px; }
        .title { font-size: 13px; font-weight: bold; margin-top: 8px; }
        .period { font-size: 9px; color: #64748b; margin-top: 2px; }

        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th {
            background: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
            padding: 5px 6px;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #475569;
            text-align: left;
        }
        td { padding: 4px 6px; border-bottom: 1px solid #f1f5f9; }
        .r { text-align: right; }
        .b { font-weight: bold; }
        tfoot td {
            border-top: 1.5px solid #cbd5e1;
            border-bottom: none;
            background: #f8fafc;
            font-weight: bold;
            padding: 6px;
        }

        .empty { text-align: center; color: #94a3b8; padding: 24px; }
        .foot {
            position: fixed; bottom: -10mm; left: 0; right: 0;
            font-size: 7.5px; color: #94a3b8;
            border-top: 1px solid #e2e8f0; padding-top: 4px;
        }
        .foot .right { float: right; }
    </style>
</head>
<body>

@php
    $meta = $report['meta'];

    $render = function ($value, array $column) {
        $format = $column['format'] ?? 'text';

        if ($value === null || $value === '') {
            return '';
        }

        return match ($format) {
            'money' => number_format((float) $value, 2),
            'number' => number_format((float) $value),
            'quantity' => rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.'),
            'percent' => number_format((float) $value, 1).'%',
            'date' => \Illuminate\Support\Carbon::parse($value)->format('d M Y'),
            default => $value,
        };
    };
@endphp

<div class="head">
    <div class="brand">{{ config('brand.name', 'KN Softic') }}</div>
    <div class="shop">{{ $business?->name }}</div>

    <div class="title">{{ $report['name'] }}</div>
    <div class="period">
        @if (($meta['dated'] ?? true) === false)
            As at {{ $printedAt->format('d M Y, H:i') }}
        @else
            {{ \Illuminate\Support\Carbon::parse($meta['from'])->format('d M Y') }}
            to {{ \Illuminate\Support\Carbon::parse($meta['to'])->format('d M Y') }}
        @endif
        @if ($meta['branch'])
            &middot; {{ $meta['branch']->name }}
        @endif
    </div>
</div>

<table>
    <thead>
        <tr>
            @foreach ($report['columns'] as $column)
                <th class="{{ ($column['align'] ?? 'left') === 'right' ? 'r' : '' }}">{{ $column['label'] }}</th>
            @endforeach
        </tr>
    </thead>

    <tbody>
        @forelse ($report['rows'] as $row)
            <tr>
                @foreach ($report['columns'] as $column)
                    <td class="{{ ($column['align'] ?? 'left') === 'right' ? 'r' : '' }} {{ ($column['emphasis'] ?? false) ? 'b' : '' }}">
                        {{ $render($row[$column['key']] ?? null, $column) }}
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td class="empty" colspan="{{ count($report['columns']) }}">Nothing in this period.</td>
            </tr>
        @endforelse
    </tbody>

    @if ($report['totals'] !== null && $report['rows']->isNotEmpty())
        <tfoot>
            <tr>
                @foreach ($report['columns'] as $column)
                    <td class="{{ ($column['align'] ?? 'left') === 'right' ? 'r' : '' }}">
                        {{ $render($report['totals'][$column['key']] ?? null, $column) }}
                    </td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>

<div class="foot">
    Completed sales only, net of returns.
    <span class="right">
        {{ $business?->name }} &middot; printed {{ $printedAt->format('d M Y, H:i') }}
    </span>
</div>

</body>
</html>
