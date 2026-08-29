@php
    use App\Support\Money;
    use Illuminate\Support\Str;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Labels — {{ $product->sku }}</title>
    <style>
        :root {
            --ink: #111827;
            --muted: #6b7280;
            --line: #d1d5db;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: #f3f4f6;
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        .toolbar {
            max-width: 900px;
            margin: 0 auto 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
        }

        .toolbar h1 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        .toolbar p {
            margin: 2px 0 0;
            font-size: 13px;
            color: var(--muted);
        }

        .toolbar form {
            display: flex;
            gap: 8px;
            align-items: center;
            font-size: 13px;
        }

        .toolbar input {
            width: 72px;
            padding: 6px 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            font: inherit;
        }

        .btn {
            padding: 7px 14px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--ink);
            font: inherit;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--ink);
            border-color: var(--ink);
            color: #fff;
        }

        .sheet {
            max-width: 900px;
            margin: 0 auto;
            padding: 16px;
            background: #fff;
            border-radius: 8px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .label {
            border: 1px dashed var(--line);
            border-radius: 4px;
            padding: 8px;
            text-align: center;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .label svg {
            width: 100%;
            height: auto;
            max-width: 90px;
            display: block;
            margin: 0 auto 6px;
        }

        .label .name {
            font-size: 10px;
            font-weight: 600;
            line-height: 1.25;
            min-height: 25px;
            overflow: hidden;
        }

        .label .meta {
            font-size: 8px;
            color: var(--muted);
            margin-top: 2px;
            font-variant-numeric: tabular-nums;
        }

        .label .price {
            font-size: 13px;
            font-weight: 700;
            margin-top: 4px;
            font-variant-numeric: tabular-nums;
        }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .sheet {
                max-width: none;
                padding: 0;
                border-radius: 0;
                gap: 0;
            }
            .label { border-style: solid; border-color: #e5e7eb; }
        }

        @page { margin: 10mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>
            <h1>{{ $product->display_name }}</h1>
            <p>{{ $copies }} {{ Str::plural('sticker', $copies) }} &middot; SKU {{ $product->sku }}</p>
        </div>
        <form method="GET" action="{{ route('labels.sheet', $product) }}">
            <label for="copies">Copies</label>
            <input id="copies" type="number" name="copies" min="1" max="{{ config('pos.labels.max_copies', 96) }}" value="{{ $copies }}">
            <button type="submit" class="btn">Update</button>
            <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
        </form>
    </div>

    <div class="sheet">
        @for ($i = 0; $i < $copies; $i++)
            <div class="label">
                {!! $qrSvg !!}
                <div class="name">{{ $product->name }}</div>
                @if ($product->medicine?->strength)
                    <div class="meta">{{ $product->medicine->strength }} {{ $product->medicine->dosage_form }}</div>
                @endif
                <div class="meta">{{ $product->sku }} &middot; {{ $product->unit }}</div>
                <div class="price">{{ Money::format($product->selling_price) }}</div>
            </div>
        @endfor
    </div>
</body>
</html>
