@php
    $hasRows = $disposals->isNotEmpty();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Disposal report — {{ $heading }}@if ($reason) — {{ $reason->getLabel() }}@endif</title>
    <style>
        :root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --loss: #b91c1c; }

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
        .card {
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 12px;
        }
        .card .k { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
        .card .v { font-size: 18px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
        .card.loss .v { color: var(--loss); }

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
        .loss { color: var(--loss); }
        .empty { padding: 32px; text-align: center; color: var(--muted); }
        .sub-line { font-size: 11.5px; color: #9ca3af; }

        .split { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

        footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid var(--line); font-size: 11px; color: var(--muted); }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .page { max-width: none; padding: 0; border-radius: 0; }
            tr { break-inside: avoid; }
        }

        @page { margin: 14mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ url()->previous() }}" class="btn btn-ghost">Back</a>
        @include('reports.partials.download-image', [
            'filename' => \Illuminate\Support\Str::slug('disposal report '.$heading),
        ])
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>

    <div class="page">
        <header>
            <div class="store">
                {{ $store['name'] ?? config('app.name') }}
                @if (! empty($store['tin'])) &middot; TIN {{ $store['tin'] }} @endif
            </div>
            <h1>{{ ucfirst($period) }} disposal report</h1>
            <div class="sub">
                {{ $heading }}
                &middot; {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}
                &middot; {{ $reason?->getLabel() ?? 'Expired and damaged' }}
                &middot; {{ $user->branch?->name ?? 'All branches' }}
            </div>
        </header>

        <div class="cards">
            <div class="card">
                <div class="k">Write-offs</div>
                <div class="v">{{ number_format($totals['entries']) }}</div>
            </div>
            <div class="card">
                <div class="k">Products affected</div>
                <div class="v">{{ number_format($totals['products']) }}</div>
            </div>
            <div class="card">
                <div class="k">Pieces disposed</div>
                <div class="v">{{ number_format($totals['pieces']) }}</div>
            </div>
            <div class="card loss">
                <div class="k">Cost lost</div>
                <div class="v">&#8369;{{ number_format($totals['value'], 2) }}</div>
            </div>
        </div>

        @if ($hasRows)
            <div class="split">
                <div>
                    <h2>By reason</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Reason</th>
                                <th class="num">Entries</th>
                                <th class="num">Pieces</th>
                                <th class="num">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byReason as $label => $row)
                                <tr>
                                    <td>{{ $label }}</td>
                                    <td class="num">{{ number_format($row['entries']) }}</td>
                                    <td class="num">{{ number_format($row['pieces']) }}</td>
                                    <td class="num loss">&#8369;{{ number_format($row['value'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="num">{{ number_format($totals['entries']) }}</td>
                                <td class="num">{{ number_format($totals['pieces']) }}</td>
                                <td class="num loss">&#8369;{{ number_format($totals['value'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div>
                    <h2>Worst products</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="num">Pieces</th>
                                <th class="num">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byProduct->take(8) as $row)
                                <tr>
                                    <td>
                                        {{ $row['product']?->name ?? 'Deleted product' }}
                                        <div class="sub-line">
                                            {{ $row['product']?->formatPieces($row['pieces']) }}
                                        </div>
                                    </td>
                                    <td class="num">{{ number_format($row['pieces']) }}</td>
                                    <td class="num loss">&#8369;{{ number_format($row['value'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($byProduct->count() > 8)
                        <p class="muted" style="font-size:11px;margin-top:6px;">
                            {{ $byProduct->count() - 8 }} more product(s) in the full list below.
                        </p>
                    @endif
                </div>
            </div>

            <h2>Every write-off</h2>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Batch</th>
                        <th>Reason</th>
                        <th class="num">Pieces</th>
                        <th class="num">Cost</th>
                        <th>Recorded by</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($disposals as $disposal)
                        <tr>
                            <td class="muted">{{ $disposal->created_at->format('d M Y') }}</td>
                            <td>
                                {{ $disposal->product?->name ?? 'Deleted product' }}
                                @if ($disposal->remarks)
                                    <div class="sub-line">{{ $disposal->remarks }}</div>
                                @endif
                            </td>
                            <td class="muted">
                                {{ $disposal->batch?->batch_number ?? '—' }}
                                @if ($disposal->batch?->expiration_date)
                                    <div class="sub-line">
                                        exp {{ $disposal->batch->expiration_date->format('m/Y') }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $disposal->type->getLabel() }}</td>
                            <td class="num">
                                {{ number_format($disposal->pieces_disposed) }}
                                @if ($disposal->product?->isDivisible())
                                    <div class="sub-line">
                                        {{ $disposal->product->formatPieces($disposal->pieces_disposed) }}
                                    </div>
                                @endif
                            </td>
                            <td class="num loss">&#8369;{{ number_format($disposal->disposal_value, 2) }}</td>
                            <td class="muted">{{ $disposal->user?->name ?? 'System' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">Total</td>
                        <td class="num">{{ number_format($totals['pieces']) }}</td>
                        <td class="num loss">&#8369;{{ number_format($totals['value'], 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @else
            <div class="empty">
                Nothing was written off in this period.
            </div>
        @endif

        <footer>
            Generated {{ now()->format('d M Y h:i A') }} by {{ $user->name }}
            &middot; Stock valued at cost.
        </footer>
    </div>
</body>
</html>
