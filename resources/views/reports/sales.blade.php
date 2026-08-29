@php
    use App\Support\Money;

    // One cashier's report, whether they asked for it themselves or a manager
    // picked them. Either way the Cashier column would repeat one name.
    $singleCashier = $cashier !== null;

    // Columns in the transaction table, so the item rows underneath and the
    // footer span the right width whichever variant is being printed.
    $txnSpan = ($singleCashier ? 6 : 7) + ($showProfit ? 1 : 0);
    $txnLabelSpan = $singleCashier ? 3 : 4;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $showProfit ? 'Sales & profit report' : 'Sales report' }} — {{ $heading }}@if ($cashier) — {{ $cashier->name }}@endif</title>
    <style>
        :root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: #f3f4f6;
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 13px;
        }

        .page {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            padding: 32px;
            border-radius: 8px;
        }

        .toolbar {
            max-width: 900px;
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

        header { border-bottom: 2px solid var(--ink); padding-bottom: 12px; margin-bottom: 20px; }
        header h1 { margin: 0 0 2px; font-size: 20px; }
        header .sub { color: var(--muted); font-size: 12px; }
        header .store { font-size: 12px; color: var(--muted); margin-bottom: 8px; }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }
        .cards.with-profit { grid-template-columns: repeat(3, 1fr); }
        .card.accent { border-color: var(--ink); }
        .card.accent .v { color: #047857; }
        .card.accent .v.loss { color: #b91c1c; }
        .card {
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 12px;
        }
        .card .k { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
        .card .v { font-size: 18px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }

        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin: 24px 0 8px; }

        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            border-bottom: 1px solid var(--line);
            padding: 6px 8px;
        }
        td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; }
        tfoot td { border-top: 2px solid var(--ink); border-bottom: 0; font-weight: 700; padding-top: 8px; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .muted { color: var(--muted); }
        .empty { padding: 32px; text-align: center; color: var(--muted); }

        .split { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

        tr.txn td { border-bottom: 0; padding-bottom: 2px; }
        tr.lines td { padding: 0 8px 8px 8px; border-bottom: 1px solid #f3f4f6; }
        ul.items { list-style: none; margin: 0; padding: 0 0 0 16px; }
        ul.items li {
            display: flex;
            gap: 8px;
            font-size: 11.5px;
            color: #4b5563;
            padding: 1px 0;
        }
        ul.items li .q { min-width: 34px; font-variant-numeric: tabular-nums; }
        ul.items li .n { flex: 1; }
        ul.items li .a { font-variant-numeric: tabular-nums; text-align: right; min-width: 64px; }
        ul.items li .lot { color: #9ca3af; }

        footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid var(--line); font-size: 11px; color: var(--muted); }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .page { max-width: none; padding: 0; border-radius: 0; }
            tr { break-inside: avoid; }
            tr.txn { break-after: avoid; }
        }

        @page { margin: 14mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="javascript:history.back()" class="btn btn-ghost">Back</a>
        @include('reports.partials.download-image', [
            'filename' => \Illuminate\Support\Str::slug(
                ($showProfit ? 'sales and profit report' : 'sales report')
                .' '.$heading
                .($cashier ? ' '.$cashier->name : '')
            ),
        ])
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>

    <div class="page">
        <header>
            <div class="store">
                {{ $store['name'] ?? config('app.name') }}
                @if (! empty($store['tin'])) &middot; TIN {{ $store['tin'] }} @endif
            </div>
            <h1>{{ ucfirst($period) }} {{ $showProfit ? 'sales &amp; profit' : 'sales' }} report</h1>
            <div class="sub">
                {{ $heading }}
                &middot; {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}
                @if ($singleCashier)
                    &middot; {{ $cashier->name }}
                @else
                    &middot; All cashiers
                @endif
                &middot; {{ $user->branch?->name ?? 'All branches' }}
            </div>
        </header>

        <div class="cards @if ($showProfit) with-profit @endif">
            <div class="card">
                <div class="k">Transactions</div>
                <div class="v">{{ number_format($totals['transactions']) }}</div>
            </div>
            <div class="card">
                <div class="k">Units sold</div>
                <div class="v">{{ number_format($totals['units']) }}</div>
            </div>
            <div class="card">
                <div class="k">Discounts</div>
                <div class="v">{{ Money::format($totals['discount']) }}</div>
            </div>
            <div class="card">
                <div class="k">Total sales</div>
                <div class="v">{{ Money::format($totals['net']) }}</div>
            </div>
            @if ($showProfit)
                <div class="card">
                    <div class="k">Cost of goods sold</div>
                    <div class="v">{{ Money::format($totals['cost']) }}</div>
                </div>
                <div class="card accent">
                    <div class="k">Gross profit &middot; {{ number_format($totals['margin'], 1) }}% margin</div>
                    <div class="v @if ($totals['profit'] < 0) loss @endif">
                        {{ Money::format($totals['profit']) }}
                    </div>
                </div>
            @endif
        </div>

        @if ($sales->isEmpty())
            <div class="empty">No completed sales in this period.</div>
        @else
            <div class="split">
                <div>
                    <h2>By payment method</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Method</th>
                                <th class="num">Txns</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byPaymentMethod as $method => $row)
                                <tr>
                                    <td>{{ $method }}</td>
                                    <td class="num">{{ $row['count'] }}</td>
                                    <td class="num">{{ Money::plain($row['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    <h2>{{ $bucketLabel }}</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>{{ $period === 'yearly' ? 'Month' : 'Date' }}</th>
                                <th class="num">Txns</th>
                                <th class="num">Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byBucket as $bucket => $row)
                                <tr>
                                    <td>
                                        {{ $period === 'yearly'
                                            ? \Illuminate\Support\Carbon::parse($bucket.'-01')->format('F Y')
                                            : \Illuminate\Support\Carbon::parse($bucket)->format('D, d M Y') }}
                                    </td>
                                    <td class="num">{{ $row['count'] }}</td>
                                    <td class="num">{{ Money::plain($row['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($showProfit)
                <h2>Profit {{ strtolower($bucketLabel) }}</h2>
                <table>
                    <thead>
                        <tr>
                            <th>{{ $period === 'yearly' ? 'Month' : 'Date' }}</th>
                            <th class="num">Txns</th>
                            <th class="num">Units</th>
                            <th class="num">Sales</th>
                            <th class="num">Cost</th>
                            <th class="num">Profit</th>
                            <th class="num">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byBucket as $bucket => $row)
                            <tr>
                                <td>
                                    {{ $period === 'yearly'
                                        ? \Illuminate\Support\Carbon::parse($bucket.'-01')->format('F Y')
                                        : \Illuminate\Support\Carbon::parse($bucket)->format('D, d M Y') }}
                                </td>
                                <td class="num">{{ $row['count'] }}</td>
                                <td class="num">{{ number_format($row['units']) }}</td>
                                <td class="num">{{ Money::plain($row['total']) }}</td>
                                <td class="num muted">{{ Money::plain($row['cost']) }}</td>
                                <td class="num">{{ Money::plain($row['profit']) }}</td>
                                <td class="num muted">{{ number_format($row['margin'], 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="num">{{ number_format($totals['transactions']) }}</td>
                            <td class="num">{{ number_format($totals['units']) }}</td>
                            <td class="num">{{ Money::plain($totals['net']) }}</td>
                            <td class="num">{{ Money::plain($totals['cost']) }}</td>
                            <td class="num">{{ Money::plain($totals['profit']) }}</td>
                            <td class="num">{{ number_format($totals['margin'], 1) }}%</td>
                        </tr>
                    </tfoot>
                </table>

                @if ($byProduct->isNotEmpty())
                    <h2>Top products by profit</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="num">Units</th>
                                <th class="num">Sales</th>
                                <th class="num">Cost</th>
                                <th class="num">Profit</th>
                                <th class="num">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byProduct as $name => $row)
                                <tr>
                                    <td>{{ $name }}</td>
                                    <td class="num">{{ number_format($row['units']) }}</td>
                                    <td class="num">{{ Money::plain($row['total']) }}</td>
                                    <td class="num muted">{{ Money::plain($row['cost']) }}</td>
                                    <td class="num">{{ Money::plain($row['profit']) }}</td>
                                    <td class="num muted">{{ number_format($row['margin'], 1) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="muted" style="font-size: 11px; margin: 6px 0 0;">
                        Ranked by profit earned, not units moved. Line sales exclude any
                        whole-ticket discount, which is not attributed to a single product.
                    </p>
                @endif

                @if ($byCategory->isNotEmpty())
                    <h2>By category</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="num">Units</th>
                                <th class="num">Sales</th>
                                <th class="num">Cost</th>
                                <th class="num">Profit</th>
                                <th class="num">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byCategory as $name => $row)
                                <tr>
                                    <td>{{ $name }}</td>
                                    <td class="num">{{ number_format($row['units']) }}</td>
                                    <td class="num">{{ Money::plain($row['total']) }}</td>
                                    <td class="num muted">{{ Money::plain($row['cost']) }}</td>
                                    <td class="num">{{ Money::plain($row['profit']) }}</td>
                                    <td class="num muted">{{ number_format($row['margin'], 1) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($byCashier->isNotEmpty())
                    <h2>By cashier</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Cashier</th>
                                <th class="num">Txns</th>
                                <th class="num">Units</th>
                                <th class="num">Sales</th>
                                <th class="num">Cost</th>
                                <th class="num">Profit</th>
                                <th class="num">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byCashier as $name => $row)
                                <tr>
                                    <td>{{ $name }}</td>
                                    <td class="num">{{ $row['count'] }}</td>
                                    <td class="num">{{ number_format($row['units']) }}</td>
                                    <td class="num">{{ Money::plain($row['total']) }}</td>
                                    <td class="num muted">{{ Money::plain($row['cost']) }}</td>
                                    <td class="num">{{ Money::plain($row['profit']) }}</td>
                                    <td class="num muted">{{ number_format($row['margin'], 1) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif

            <h2>Transactions</h2>
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date &amp; time</th>
                        @unless ($singleCashier)
                            <th>Cashier</th>
                        @endunless
                        <th class="num">Items</th>
                        <th class="num">Subtotal</th>
                        <th class="num">Discount</th>
                        <th class="num">Total</th>
                        @if ($showProfit)
                            <th class="num">Profit</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sales as $sale)
                        <tr class="txn">
                            <td>{{ $sale->invoice_number }}</td>
                            <td class="muted">{{ $sale->sale_date->format('d M Y h:i A') }}</td>
                            @unless ($singleCashier)
                                <td class="muted">{{ $sale->cashier?->name ?? '—' }}</td>
                            @endunless
                            <td class="num">{{ $sale->items_count }}</td>
                            <td class="num">{{ Money::plain($sale->subtotal) }}</td>
                            <td class="num">{{ Money::plain($sale->discount) }}</td>
                            <td class="num">{{ Money::plain($sale->total_amount) }}</td>
                            @if ($showProfit)
                                <td class="num">{{ Money::plain($sale->gross_profit) }}</td>
                            @endif
                        </tr>
                        <tr class="lines">
                            <td colspan="{{ $txnSpan }}">
                                <ul class="items">
                                    @foreach ($sale->items as $item)
                                        <li>
                                            <span class="q">{{ $item->quantity }}&times;</span> <span class="q">{{ $item->unit_label }}</span>
                                            <span class="n">
                                                {{ $item->product?->name ?? 'Item' }}
                                                @if ($item->product?->medicine?->strength)
                                                    <span class="lot">{{ $item->product->medicine->strength }}</span>
                                                @endif
                                                <span class="lot">&#64; {{ Money::plain($item->unit_price) }}</span>
                                            </span>
                                            <span class="a">{{ Money::plain($item->subtotal) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="{{ $txnLabelSpan }}">Total</td>
                        <td class="num">{{ Money::plain($totals['gross']) }}</td>
                        <td class="num">{{ Money::plain($totals['discount']) }}</td>
                        <td class="num">{{ Money::plain($totals['net']) }}</td>
                        @if ($showProfit)
                            <td class="num">{{ Money::plain($totals['profit']) }}</td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        @endif

        <footer>
            Generated {{ now()->format('d M Y h:i A') }} by {{ $user->name }}
            @if ($scopedToSelf)
                &middot; Covers this account's own transactions only.
            @elseif ($singleCashier)
                &middot; Covers {{ $cashier->name }}'s transactions only.
            @endif
        </footer>
    </div>
</body>
</html>
