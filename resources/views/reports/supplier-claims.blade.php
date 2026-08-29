@php
    use App\Support\Money;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supplier claim — {{ $heading }}@if ($supplier) — {{ $supplier->name }}@endif</title>
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
            margin: 0 auto 24px;
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

        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 20px; }
        .parties .name { font-weight: 700; }
        .parties .line { color: var(--muted); }

        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin: 0 0 6px; }

        .lede { margin: 0 0 18px; }

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
        td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        tfoot td { border-top: 2px solid var(--ink); border-bottom: 0; font-weight: 700; padding-top: 10px; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .muted { color: var(--muted); }
        .loss { color: var(--loss); }
        .sub-line { font-size: 11.5px; color: #9ca3af; }
        .grand { font-size: 15px; }
        .empty { padding: 32px; text-align: center; color: var(--muted); }

        .note {
            margin-top: 16px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 6px;
            color: var(--muted);
            font-size: 12px;
        }
        .note.warn { border-color: #fecaca; background: #fef2f2; color: #7f1d1d; }

        .signatures {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            margin-top: 44px;
        }
        .sign .rule { border-top: 1px solid var(--ink); padding-top: 6px; }
        .sign .role { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
        .sign .who { font-size: 12px; margin-top: 2px; }

        footer { margin-top: 28px; padding-top: 12px; border-top: 1px solid var(--line); font-size: 11px; color: var(--muted); }

        .summary .page { margin-bottom: 24px; }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .page { max-width: none; padding: 0; border-radius: 0; margin: 0; }
            .page + .page { break-before: page; }
            tr { break-inside: avoid; }
            .signatures { break-inside: avoid; }
        }

        @page { margin: 14mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="{{ url()->previous() }}" class="btn btn-ghost">Back</a>
        @include('reports.partials.download-image', [
            'filename' => \Illuminate\Support\Str::slug('supplier claims '.$heading),
        ])
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>

    @forelse ($claims as $claim)
        @php
            $party = $claim['supplier'];
            $rows = $claim['rows'];
            // An admin sees every store at once, so a claim only carries a
            // branch address when all of its lines came from one branch.
            $branches = $rows->pluck('branch')->filter()->unique('id');
            $branch = $branches->count() === 1 ? $branches->first() : null;
        @endphp
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
                    @if (! empty($store['tin']))
                        <div class="store-line">TIN: {{ $store['tin'] }}</div>
                    @endif
                </div>
                <div class="doc">
                    <h1>Refund / replacement claim</h1>
                    <div class="store-line">{{ $heading }}</div>
                    <div class="store-line">
                        {{ $from->format('d M Y') }} &ndash; {{ $to->format('d M Y') }}
                    </div>
                    <div class="store-line">
                        {{ $reason?->getLabel() ?? 'Expired and damaged' }}
                    </div>
                </div>
            </header>

            <div class="parties">
                <div>
                    <h2>To supplier</h2>
                    @if ($party)
                        <div class="name">{{ $party->name }}</div>
                        @if ($party->contact_person)
                            <div class="line">Attn: {{ $party->contact_person }}</div>
                        @endif
                        @if ($party->address)
                            <div class="line">{{ $party->address }}</div>
                        @endif
                        @if ($party->contact_number)
                            <div class="line">Tel: {{ $party->contact_number }}</div>
                        @endif
                        @if ($party->email)
                            <div class="line">{{ $party->email }}</div>
                        @endif
                    @else
                        <div class="name">No supplier on record</div>
                        <div class="line">Stock written off without a tracked batch.</div>
                    @endif
                </div>
                <div>
                    <h2>Claimed by</h2>
                    <div class="name">{{ $branch?->name ?? ($store['name'] ?? config('app.name')) }}</div>
                    @if ($branch)
                        @if ($branch->address)
                            <div class="line">{{ $branch->address }}</div>
                        @endif
                        @if ($branch->contact_number)
                            <div class="line">Tel: {{ $branch->contact_number }}</div>
                        @endif
                    @else
                        <div class="line">{{ $branches->pluck('name')->join(', ') }}</div>
                    @endif
                </div>
            </div>

            @if ($party)
                <p class="lede">
                    The items below were withdrawn from sale and are being returned for
                    refund or replacement. Batch numbers and expiry dates are as printed
                    on the packs. Please confirm receipt on the acknowledgement below.
                </p>
            @endif

            <table>
                <thead>
                    <tr>
                        <th style="width: 30px;">#</th>
                        <th>Item</th>
                        <th style="width: 110px;">Batch no.</th>
                        <th style="width: 80px;">Expiry</th>
                        @if (! $branch)
                            <th style="width: 110px;">Branch</th>
                        @endif
                        @unless ($reason)
                            <th style="width: 110px;">Reason</th>
                        @endunless
                        <th class="num" style="width: 90px;">Qty</th>
                        <th class="num" style="width: 90px;">Unit cost</th>
                        <th class="num" style="width: 110px;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $index => $row)
                        @php
                            $product = $row->product;
                            $packCost = $row->batch?->cost_price ?? $product?->cost_price;
                            $perPiece = $product ? $product->costPerPiece((float) $packCost) : 0.0;
                        @endphp
                        <tr>
                            <td class="muted">{{ $index + 1 }}</td>
                            <td>
                                {{ $product?->name ?? 'Deleted product' }}
                                @if ($product?->sku)
                                    <div class="sub-line">{{ $product->sku }}</div>
                                @endif
                            </td>
                            <td>{{ $row->batch?->batch_number ?? '—' }}</td>
                            <td>{{ $row->batch?->expiration_date?->format('m/Y') ?? '—' }}</td>
                            @if (! $branch)
                                <td class="muted">{{ $row->branch?->name ?? '—' }}</td>
                            @endif
                            @unless ($reason)
                                <td>{{ $row->type->getLabel() }}</td>
                            @endunless
                            <td class="num">
                                {{ number_format($row->pieces_disposed) }}
                                @if ($product?->isDivisible())
                                    <div class="sub-line">{{ $product->formatPieces($row->pieces_disposed) }}</div>
                                @endif
                            </td>
                            <td class="num">{{ Money::plain($perPiece) }}</td>
                            <td class="num loss">{{ Money::plain($row->disposal_value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="grand">
                        <td colspan="{{ 4 + ($reason ? 0 : 1) + ($branch ? 0 : 1) }}">Total claimed</td>
                        <td class="num">{{ number_format($claim['pieces']) }}</td>
                        <td></td>
                        <td class="num loss">{{ Money::symbol() }}{{ Money::plain($claim['value']) }}</td>
                    </tr>
                </tfoot>
            </table>

            @if ($party)
                <div class="note">
                    Unit cost is the delivered cost per piece for the batch shown, so the
                    total is what the stock was bought for, not its shelf price.
                </div>

                <div class="signatures">
                    <div class="sign">
                        <div style="height: 40px;"></div>
                        <div class="rule">
                            <div class="role">Prepared by</div>
                            <div class="who">{{ $user->name }}</div>
                        </div>
                    </div>
                    <div class="sign">
                        <div style="height: 40px;"></div>
                        <div class="rule">
                            <div class="role">Approved by</div>
                            <div class="who">&nbsp;</div>
                        </div>
                    </div>
                    <div class="sign">
                        <div style="height: 40px;"></div>
                        <div class="rule">
                            <div class="role">Received by (supplier)</div>
                            <div class="who">Date: ____________</div>
                        </div>
                    </div>
                </div>
            @else
                <div class="note warn">
                    These write-offs have no batch behind them, so no supplier can be
                    billed for them. They are listed here only so the period's totals
                    add up. Batch tracking the affected products makes them claimable
                    next time.
                </div>
            @endif

            <footer>
                {{ $claim['entries'] }} line(s) &middot;
                Prepared {{ now()->format('d M Y h:i A') }} by {{ $user->name }}
                @if ($claims->count() > 1)
                    &middot; Sheet {{ $loop->iteration }} of {{ $claims->count() }}
                @endif
            </footer>
        </div>
    @empty
        <div class="page">
            <header>
                <div>
                    <div class="store-name">{{ $store['name'] ?? config('app.name') }}</div>
                </div>
                <div class="doc">
                    <h1>Refund / replacement claim</h1>
                    <div class="store-line">{{ $heading }}</div>
                </div>
            </header>
            <div class="empty">
                Nothing was written off in this period{{ $supplier ? ' for '.$supplier->name : '' }}.
            </div>
        </div>
    @endforelse
</body>
</html>
