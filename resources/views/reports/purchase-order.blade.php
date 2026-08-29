@php
    $supplier = $purchase->supplier;
    $branch = $purchase->branch;
    $items = $purchase->items;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase order {{ $purchase->reference_number }}</title>
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

        header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            border-bottom: 2px solid var(--ink);
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        header h1 { margin: 0 0 2px; font-size: 20px; letter-spacing: 0.02em; }
        header .store-name { font-size: 15px; font-weight: 700; }
        header .store-line { font-size: 12px; color: var(--muted); }
        header .doc { text-align: right; white-space: nowrap; }
        header .ref { font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; font-size: 15px; font-weight: 700; }

        .status {
            display: inline-block;
            margin-top: 6px;
            padding: 2px 8px;
            border: 1px solid var(--line);
            border-radius: 999px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
        }

        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        .parties h2 { margin: 0 0 6px; }
        .parties .name { font-weight: 700; }
        .parties .line { color: var(--muted); }

        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); margin: 24px 0 8px; }

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
        .sub-line { font-size: 11.5px; color: #9ca3af; }
        .grand { font-size: 15px; }
        .empty { padding: 32px; text-align: center; color: var(--muted); }

        .remarks {
            margin-top: 20px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 6px;
            white-space: pre-line;
        }

        .signatures {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            margin-top: 48px;
        }
        .sign .rule { border-top: 1px solid var(--ink); padding-top: 6px; }
        .sign .role { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
        .sign .who { font-size: 12px; margin-top: 2px; }

        footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid var(--line); font-size: 11px; color: var(--muted); }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .page { max-width: none; padding: 0; border-radius: 0; }
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
            'filename' => \Illuminate\Support\Str::slug('purchase order '.$purchase->reference_number),
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
                @if (! empty($store['tin']))
                    <div class="store-line">TIN: {{ $store['tin'] }}</div>
                @endif
            </div>
            <div class="doc">
                <h1>Purchase order</h1>
                <div class="ref">{{ $purchase->reference_number }}</div>
                <div class="store-line">Date: {{ $purchase->purchase_date?->format('d M Y') ?? '—' }}</div>
                <div class="status">{{ $purchase->status->getLabel() }}</div>
            </div>
        </header>

        <div class="parties">
            <div>
                <h2>Supplier</h2>
                <div class="name">{{ $supplier?->name ?? '—' }}</div>
                @if ($supplier?->contact_person)
                    <div class="line">Attn: {{ $supplier->contact_person }}</div>
                @endif
                @if ($supplier?->address)
                    <div class="line">{{ $supplier->address }}</div>
                @endif
                @if ($supplier?->contact_number)
                    <div class="line">Tel: {{ $supplier->contact_number }}</div>
                @endif
                @if ($supplier?->email)
                    <div class="line">{{ $supplier->email }}</div>
                @endif
            </div>
            <div>
                <h2>Deliver to</h2>
                <div class="name">{{ $branch?->name ?? ($store['name'] ?? config('app.name')) }}</div>
                @if ($branch?->address)
                    <div class="line">{{ $branch->address }}</div>
                @endif
                @if ($branch?->contact_number)
                    <div class="line">Tel: {{ $branch->contact_number }}</div>
                @endif
            </div>
        </div>

        @if ($items->isNotEmpty())
            <table>
                <thead>
                    <tr>
                        <th style="width: 32px;">#</th>
                        <th>Item</th>
                        <th class="num" style="width: 120px;">Qty ordered</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $index => $item)
                        <tr>
                            <td class="muted">{{ $index + 1 }}</td>
                            <td>
                                {{ $item->product?->name ?? 'Item' }}
                                @php
                                    $note = array_filter([
                                        $item->product?->medicine?->generic_name,
                                        $item->product?->medicine?->dosage_form,
                                        $item->product?->sku,
                                    ]);
                                @endphp
                                @if ($note !== [])
                                    <div class="sub-line">{{ implode(' · ', $note) }}</div>
                                @endif
                            </td>
                            <td class="num">
                                {{ number_format($item->quantity) }}
                                <span class="muted">{{ $item->product?->unit ?? '' }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="grand">
                        <td colspan="2">Total</td>
                        <td class="num">{{ number_format($items->sum('quantity')) }}</td>
                    </tr>
                </tfoot>
            </table>
        @else
            <div class="empty">This order has no items yet.</div>
        @endif

        @if ($purchase->remarks)
            <h2>Remarks</h2>
            <div class="remarks">{{ $purchase->remarks }}</div>
        @endif

        <div class="signatures">
            <div class="sign">
                <div style="height: 40px;"></div>
                <div class="rule">
                    <div class="role">Prepared by</div>
                    <div class="who">{{ $purchase->creator?->name ?? $user->name }}</div>
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

        <footer>
            {{ $items->count() }} line(s) &middot; Printed {{ now()->format('d M Y h:i A') }} by {{ $user->name }}
            &middot; Please quote {{ $purchase->reference_number }} on your invoice and delivery receipt.
        </footer>
    </div>
</body>
</html>
