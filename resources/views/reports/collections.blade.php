@php
    use App\Support\Money;
    use Illuminate\Support\Str;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $methodLabel }} collections — {{ $heading }}</title>
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

        .headline {
            border: 2px solid var(--ink);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 16px;
        }
        .headline .k { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
        .headline .v { font-size: 26px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .headline .note { font-size: 11px; color: var(--muted); text-align: right; max-width: 320px; }

        .cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 24px; }
        .cards.single { grid-template-columns: 1fr; }
        .card { border: 1px solid var(--line); border-radius: 6px; padding: 12px; }
        .card .k { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
        .card .v { font-size: 18px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
        .card .n { font-size: 11px; color: var(--muted); margin-top: 2px; }

        h2 {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            margin: 28px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid var(--line);
        }
        h3 { font-size: 12px; color: var(--muted); font-weight: 600; margin: 16px 0 6px; }

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
        .ref { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: 0.02em; }
        .muted { color: var(--muted); }
        .warn { color: #b45309; }
        .empty { padding: 24px; text-align: center; color: var(--muted); border: 1px dashed var(--line); border-radius: 6px; }

        .split { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }

        .tick { width: 28px; }
        .tick .box { display: inline-block; width: 12px; height: 12px; border: 1px solid #9ca3af; border-radius: 2px; }

        footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid var(--line); font-size: 11px; color: var(--muted); }
        .sign { margin-top: 28px; display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }
        .sign div { border-top: 1px solid var(--ink); padding-top: 4px; font-size: 11px; color: var(--muted); }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .page { max-width: none; padding: 0; border-radius: 0; }
            tr { break-inside: avoid; }
            h2 { break-after: avoid; }
        }

        @page { margin: 14mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="javascript:history.back()" class="btn btn-ghost">Back</a>
        @include('reports.partials.download-image', [
            'filename' => \Illuminate\Support\Str::slug('collections report '.$methodLabel.' '.$heading),
        ])
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>

    <div class="page">
        <header>
            <div class="store">
                {{ $store['name'] ?? config('app.name') }}
                @if (! empty($store['tin'])) &middot; TIN {{ $store['tin'] }} @endif
            </div>
            <h1>{{ ucfirst($period) }} {{ $methodLabel }} collections</h1>
            <div class="sub">
                {{ $heading }}
                &middot; {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}
                &middot; {{ $user->branch?->name ?? 'All branches' }}
            </div>
        </header>

        <div class="headline">
            <div>
                <div class="k">Total to withdraw</div>
                <div class="v">{{ Money::format($grandTotal) }}</div>
            </div>
            <div class="note">
                Across {{ number_format($transactions) }}
                {{ Str::plural('payment', $transactions) }}
                on {{ $methodLabel }}.
                Match this against
                {{ $singleMethod ? 'the provider statement' : 'your provider statements' }}
                before cashing out — the shop's record and theirs should agree
                to the peso.
            </div>
        </div>

        <div class="cards @if ($singleMethod) single @endif">
            @foreach ($sections as $section)
                <div class="card">
                    <div class="k">{{ $section['method']->getLabel() }}</div>
                    <div class="v">{{ Money::format($section['total']) }}</div>
                    <div class="n">
                        {{ number_format($section['count']) }}
                        {{ Str::plural('payment', $section['count']) }}
                        @if ($section['missing'] > 0)
                            &middot;
                            <span class="warn">
                                {{ $section['missing'] }} without a reference
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($missingReferences > 0)
            <p class="muted" style="margin: -12px 0 24px; font-size: 11.5px;">
                <strong class="warn">Note:</strong>
                {{ $missingReferences }}
                {{ Str::plural('payment', $missingReferences) }}
                {{ $missingReferences === 1 ? 'has' : 'have' }} no reference number —
                these were rung up before the reference became compulsory. The money
                still counts towards the total; it just cannot be matched line by line.
            </p>
        @endif

        @foreach ($sections as $section)
            <h2>{{ $section['method']->getLabel() }} &middot; {{ Money::format($section['total']) }}</h2>

            @if ($section['sales']->isEmpty())
                <div class="empty">
                    No {{ $section['method']->getLabel() }} payments in this period.
                </div>
            @else
                @if ($section['byDay']->count() > 1)
                    <h3>Day by day</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="num">Payments</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($section['byDay'] as $day => $row)
                                <tr>
                                    <td>{{ \Illuminate\Support\Carbon::parse($day)->format('D, d M Y') }}</td>
                                    <td class="num">{{ $row['count'] }}</td>
                                    <td class="num">{{ Money::plain($row['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="num">{{ number_format($section['count']) }}</td>
                                <td class="num">{{ Money::plain($section['total']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                @endif

                <h3>Every payment</h3>
                <table>
                    <thead>
                        <tr>
                            <th class="tick"></th>
                            <th>Date &amp; time</th>
                            <th>Reference no.</th>
                            <th>Invoice</th>
                            <th>Cashier</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['sales'] as $sale)
                            <tr>
                                {{-- Somewhere to tick each line off against the
                                     provider's statement while reconciling. --}}
                                <td class="tick"><span class="box"></span></td>
                                <td class="muted">{{ $sale->sale_date->format('d M Y h:i A') }}</td>
                                <td class="ref">
                                    @if ($sale->payment_reference)
                                        {{ $sale->payment_reference }}
                                    @else
                                        <span class="warn">— none recorded —</span>
                                    @endif
                                </td>
                                <td class="muted">{{ $sale->invoice_number }}</td>
                                <td class="muted">{{ $sale->cashier?->name ?? '—' }}</td>
                                <td class="num">{{ Money::plain($sale->total_amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5">
                                {{ $section['method']->getLabel() }} total
                            </td>
                            <td class="num">{{ Money::plain($section['total']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        @endforeach

        <div class="sign">
            <div>Reconciled by &amp; date</div>
            <div>Amount withdrawn &amp; date</div>
        </div>

        <footer>
            Generated {{ now()->format('d M Y h:i A') }} by {{ $user->name }}
            &middot; {{ $methodLabel }} only
            &middot; Completed sales only — voided and refunded tickets are excluded.
        </footer>
    </div>
</body>
</html>
