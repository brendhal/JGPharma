@php
    use App\Support\Money;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supplier price comparison — {{ $window }}@if ($category) — {{ $category->name }}@endif</title>
    <style>
        :root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --best: #166534; --best-bg: #f0fdf4; --over: #b91c1c; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: #f3f4f6;
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 12.5px;
        }

        .page {
            max-width: 1280px;
            margin: 0 auto 24px;
            background: #fff;
            padding: 32px;
            border-radius: 8px;
        }

        .toolbar {
            max-width: 1280px;
            margin: 0 auto 16px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .btn {
            padding: 8px 16px;
            border: 1px solid var(--ink);
            border-radius: 6px;
            background: var(--ink);
            color: #fff;
            font: inherit;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-ghost { background: #fff; color: var(--ink); border-color: var(--line); }

        header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            border-bottom: 2px solid var(--ink);
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        header h1 { margin: 0 0 2px; font-size: 19px; }
        header .store-name { font-size: 15px; font-weight: 700; }
        header .store-line { font-size: 12px; color: var(--muted); }
        header .doc { text-align: right; white-space: nowrap; }

        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin: 0 0 6px; }

        .lede { margin: 0 0 18px; max-width: 70ch; }

        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 22px; }
        .stat { border: 1px solid var(--line); border-radius: 6px; padding: 10px 12px; }
        .stat .label { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
        .stat .value { font-size: 18px; font-weight: 700; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .stat .caption { font-size: 11px; color: var(--muted); margin-top: 2px; }
        .stat.saving .value { color: var(--best); }

        .scroll { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            border-bottom: 1px solid var(--line);
            padding: 6px 8px;
            vertical-align: bottom;
        }
        td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        tfoot td { border-top: 2px solid var(--ink); border-bottom: 0; font-weight: 700; padding-top: 10px; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .muted { color: var(--muted); }
        .sub-line { font-size: 10.5px; color: #9ca3af; white-space: nowrap; }
        .empty { padding: 32px; text-align: center; color: var(--muted); }

        td.quote { white-space: nowrap; }
        td.best { background: var(--best-bg); color: var(--best); font-weight: 700; }
        td.best .sub-line { color: #4d7c5f; }
        td.none { color: #d1d5db; text-align: center; }
        .over { color: var(--over); }
        .win { color: var(--best); font-weight: 700; }
        .tag {
            display: inline-block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 0 6px;
            color: var(--muted);
        }

        .note {
            margin-top: 16px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 6px;
            color: var(--muted);
            font-size: 11.5px;
        }
        .note ul { margin: 6px 0 0; padding-left: 18px; }

        .guide-supplier { margin-top: 22px; break-inside: avoid; }
        .guide-supplier:first-of-type { margin-top: 0; }
        .guide-head { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; border-bottom: 1px solid var(--ink); padding-bottom: 4px; margin-bottom: 6px; }
        .guide-head .name { font-size: 14px; font-weight: 700; }
        .guide-head .contact { font-size: 11px; color: var(--muted); }

        footer { margin-top: 28px; padding-top: 12px; border-top: 1px solid var(--line); font-size: 11px; color: var(--muted); }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .page { max-width: none; padding: 0; border-radius: 0; margin: 0; }
            .page + .page { break-before: page; }
            .scroll { overflow: visible; }
            tr { break-inside: avoid; }
        }

        @page { size: A4 landscape; margin: 10mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ url()->previous() }}" class="btn btn-ghost">Back</a>
        @include('reports.partials.download-image', [
            'filename' => \Illuminate\Support\Str::slug('supplier price comparison '.$window),
        ])
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>

    <div class="page">
        <header>
            <div>
                <div class="store-name">{{ $store['name'] ?? config('app.name') }}</div>
                @if (! empty($store['address']))
                    <div class="store-line">{{ $store['address'] }}</div>
                @endif
                @if (! empty($store['city']))
                    <div class="store-line">{{ $store['city'] }}</div>
                @endif
                @if (! empty($store['contact']))
                    <div class="store-line">Tel: {{ $store['contact'] }}</div>
                @endif
            </div>
            <div class="doc">
                <h1>Supplier price comparison</h1>
                <div class="store-line">{{ $window }}</div>
                <div class="store-line">
                    @if ($from)
                        {{ $from->format('d M Y') }} &ndash; {{ $to->format('d M Y') }}
                    @else
                        Every order on record, to {{ $to->format('d M Y') }}
                    @endif
                </div>
                <div class="store-line">
                    {{ $category?->name ?? 'All categories' }}@if ($search) &middot; &ldquo;{{ $search }}&rdquo; @endif
                </div>
            </div>
        </header>

        <p class="lede">
            The price each supplier last charged for the same item, side by side. The
            cheapest is shaded — order that line from that supplier. Prices are per whole
            pack, the unit a purchase order is written in.
        </p>

        <div class="stats">
            <div class="stat">
                <div class="label">Items compared</div>
                <div class="value">{{ number_format($totals['products']) }}</div>
                <div class="caption">{{ number_format($totals['comparable']) }} with more than one supplier</div>
            </div>
            <div class="stat">
                <div class="label">Suppliers on the sheet</div>
                <div class="value">{{ number_format($totals['suppliers']) }}</div>
                <div class="caption">Quoting at least one of these items</div>
            </div>
            <div class="stat">
                <div class="label">Spent on these items</div>
                <div class="value">{{ Money::format($totals['spend']) }}</div>
                <div class="caption">Ordered in this window</div>
            </div>
            <div class="stat saving">
                <div class="label">Could have been saved</div>
                <div class="value">{{ Money::format($totals['overspend']) }}</div>
                <div class="caption">Same volume, always at the cheapest price</div>
            </div>
        </div>

        @if ($rows->isEmpty())
            <div class="empty">
                No item was ordered from more than one supplier in this window, so there is
                nothing to compare yet. Prices appear here as soon as the same product has
                been ordered from a second supplier.
            </div>
        @else
            <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 26px;">#</th>
                            <th style="min-width: 190px;">Item</th>
                            <th class="num" style="width: 78px;">Bought</th>
                            @foreach ($suppliers as $supplier)
                                <th class="num" style="min-width: 96px;">{{ $supplier->name }}</th>
                            @endforeach
                            <th style="min-width: 120px;">Order from</th>
                            <th class="num" style="width: 92px;">Spread</th>
                            <th class="num" style="width: 104px;">Overspend</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $index => $row)
                            @php
                                $product = $row['product'];
                                $best = $row['best'];
                            @endphp
                            <tr>
                                <td class="muted">{{ $index + 1 }}</td>
                                <td>
                                    {{ $product->name }}
                                    <div class="sub-line">
                                        {{ $product->sku }}
                                        @if ($product->category) &middot; {{ $product->category->name }} @endif
                                        &middot; per {{ $product->unit ?: 'unit' }}
                                    </div>
                                </td>
                                <td class="num">
                                    {{ number_format($row['quantity']) }}
                                    <div class="sub-line">{{ $product->unit ?: 'unit' }}</div>
                                </td>

                                @foreach ($suppliers as $supplier)
                                    @php $quote = $row['quotes'][$supplier->id] ?? null; @endphp
                                    @if (! $quote)
                                        <td class="none">&mdash;</td>
                                    @else
                                        <td class="num quote {{ $quote['is_best'] ? 'best' : '' }}">
                                            {{ Money::plain($quote['price']) }}
                                            <div class="sub-line">
                                                @if ($quote['is_best'])
                                                    {{ $quote['date']->format('d M y') }}
                                                @else
                                                    +{{ Money::plain($quote['difference']) }}
                                                    ({{ number_format($quote['difference_percent'], 1) }}%)
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                @endforeach

                                <td>
                                    <span class="win">{{ $best['supplier']->name }}</span>
                                    <div class="sub-line">
                                        {{ Money::plain($best['price']) }} on {{ $best['date']->format('d M Y') }}
                                        @if ($product->isDivisible())
                                            &middot; {{ Money::plain($product->costPerPiece($best['price'])) }}/{{ $product->piece_unit ?: 'pc' }}
                                        @endif
                                    </div>
                                </td>
                                <td class="num">
                                    @if (count($row['quotes']) > 1)
                                        {{ Money::plain($row['spread']) }}
                                        <div class="sub-line">{{ number_format($row['spread_percent'], 1) }}%</div>
                                    @else
                                        <span class="tag">only supplier</span>
                                    @endif
                                </td>
                                <td class="num {{ $row['overspend'] > 0 ? 'over' : 'muted' }}">
                                    {{ Money::plain($row['overspend']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="{{ 3 + $suppliers->count() + 2 }}">
                                Total overspend against the cheapest price available
                            </td>
                            <td class="num over">{{ Money::symbol() }}{{ Money::plain($totals['overspend']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif

        <div class="note">
            How to read this sheet:
            <ul>
                <li>A supplier's price is what they charged on their most recent order for that item — pending orders included, cancelled ones ignored.</li>
                <li>&ldquo;Bought&rdquo; is the quantity ordered from everyone in this window, and &ldquo;overspend&rdquo; is what that same quantity would have cost at the cheapest price on the row.</li>
                <li>A dash means that supplier has never been ordered that item, not that they cannot supply it. Ask them for a quote and it will appear here after the first order.</li>
                <li>Price alone does not settle an order — delivery time, expiry dating, minimum quantity and credit terms are not in this system.</li>
            </ul>
        </div>

        <footer>
            {{ $rows->count() }} item(s) &middot;
            Prepared {{ now()->format('d M Y h:i A') }} by {{ $user->name }}
        </footer>
    </div>

    @if ($best_by_supplier->isNotEmpty())
        <div class="page">
            <header>
                <div>
                    <div class="store-name">{{ $store['name'] ?? config('app.name') }}</div>
                    <div class="store-line">Order guide</div>
                </div>
                <div class="doc">
                    <h1>What to order from whom</h1>
                    <div class="store-line">{{ $window }}</div>
                    <div class="store-line">Cheapest supplier per item</div>
                </div>
            </header>

            <p class="lede">
                The same comparison split by supplier, so each list can be sent as it stands.
                An item appears under one supplier only: the one quoting the lowest price.
            </p>

            @foreach ($best_by_supplier as $guide)
                @php $party = $guide['supplier']; @endphp
                <div class="guide-supplier">
                    <div class="guide-head">
                        <div>
                            <div class="name">{{ $party->name }}</div>
                            <div class="contact">
                                {{ collect([$party->contact_person, $party->contact_number, $party->email])->filter()->join(' · ') ?: 'No contact on file' }}
                            </div>
                        </div>
                        <div class="num">
                            <div class="sub-line">{{ $guide['lines']->count() }} item(s) cheapest here</div>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th style="min-width: 200px;">Item</th>
                                <th class="num" style="width: 100px;">Their price</th>
                                <th class="num" style="width: 110px;">Next cheapest</th>
                                <th class="num" style="width: 96px;">You save</th>
                                <th class="num" style="width: 90px;">Last bought</th>
                                <th class="num" style="width: 90px;">Order qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($guide['lines'] as $row)
                                @php
                                    $product = $row['product'];
                                    $best = $row['best'];
                                    $runnerUp = collect($row['quotes'])
                                        ->reject(fn (array $quote): bool => $quote['is_best'])
                                        ->sortBy('price')
                                        ->first();
                                @endphp
                                <tr>
                                    <td>
                                        {{ $product->name }}
                                        <div class="sub-line">{{ $product->sku }} &middot; per {{ $product->unit ?: 'unit' }}</div>
                                    </td>
                                    <td class="num win">{{ Money::plain($best['price']) }}</td>
                                    <td class="num">
                                        @if ($runnerUp)
                                            {{ Money::plain($runnerUp['price']) }}
                                            <div class="sub-line">{{ $runnerUp['supplier']->name }}</div>
                                        @else
                                            <span class="tag">only supplier</span>
                                        @endif
                                    </td>
                                    <td class="num {{ $runnerUp ? 'win' : 'muted' }}">
                                        {{ $runnerUp ? Money::plain($runnerUp['price'] - $best['price']) : '—' }}
                                    </td>
                                    <td class="num muted">{{ $best['date']->format('d M y') }}</td>
                                    <td class="num">&nbsp;</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach

            <footer>
                Prepared {{ now()->format('d M Y h:i A') }} by {{ $user->name }} &middot;
                Write the quantity you want in the last column before sending.
            </footer>
        </div>
    @endif
</body>
</html>
