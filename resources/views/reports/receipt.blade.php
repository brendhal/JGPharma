@php
    use App\Enums\SaleStatus;
    use App\Support\Money;

    $isVoided = $sale->status === SaleStatus::VOIDED;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $sale->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 16px;
            background: #e5e7eb;
            font-family: ui-monospace, "SFMono-Regular", "Menlo", "Consolas", monospace;
            font-size: 12px;
            line-height: 1.45;
            color: #000;
        }

        .actions {
            width: 80mm;
            margin: 0 auto 12px;
            display: flex;
            gap: 8px;
        }

        .btn {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #111827;
            border-radius: 6px;
            background: #111827;
            color: #fff;
            font-family: ui-sans-serif, system-ui, sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }

        .receipt {
            width: 80mm;
            margin: 0 auto;
            padding: 6mm 5mm;
            background: #fff;
        }

        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .muted { color: #4b5563; }
        .upper { text-transform: uppercase; }

        .store-name {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        hr {
            border: 0;
            border-top: 1px dashed #000;
            margin: 6px 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 0; vertical-align: top; }

        .items td { padding: 2px 0; }
        .items .qty { width: 30px; }
        .items .amt { width: 68px; text-align: right; font-variant-numeric: tabular-nums; }
        .items .line-note { font-size: 10px; color: #4b5563; }

        .totals td { padding: 1px 0; font-variant-numeric: tabular-nums; }
        .totals .amt { text-align: right; }
        .totals .grand td {
            font-size: 14px;
            font-weight: 700;
            padding-top: 4px;
        }

        .meta td { padding: 1px 0; }
        .meta .k { width: 62px; color: #4b5563; }

        .void-stamp {
            margin: 8px 0;
            padding: 4px;
            border: 2px solid #000;
            text-align: center;
            font-weight: 700;
            letter-spacing: 0.2em;
        }

        .footer { font-size: 10px; }

        @media print {
            body { padding: 0; background: #fff; }
            .actions { display: none; }
            .receipt { width: auto; padding: 0; }
        }

        @page { size: 80mm auto; margin: 3mm; }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" class="btn" onclick="window.print()">Print receipt</button>
    </div>

    <div class="receipt">
        <div class="center">
            <div class="store-name">{{ $store['name'] ?? config('app.name') }}</div>
            @if ($sale->branch)
                <div>{{ $sale->branch->name }}</div>
                @if ($sale->branch->address)
                    <div class="muted">{{ $sale->branch->address }}</div>
                @endif
                @if ($sale->branch->contact_number)
                    <div class="muted">Tel: {{ $sale->branch->contact_number }}</div>
                @endif
            @else
                <div class="muted">{{ $store['address'] ?? '' }}</div>
                <div class="muted">{{ $store['city'] ?? '' }}</div>
            @endif
            @if (! empty($store['tin']))
                <div class="muted">TIN: {{ $store['tin'] }}</div>
            @endif
            @if (! empty($store['business_style']))
                <div class="muted">{{ $store['business_style'] }}</div>
            @endif
        </div>

        @if ($isVoided)
            <div class="void-stamp">VOIDED</div>
        @endif

        <hr>

        <table class="meta">
            <tr>
                <td class="k">Invoice</td>
                <td class="bold">{{ $sale->invoice_number }}</td>
            </tr>
            <tr>
                <td class="k">Date</td>
                <td>{{ $sale->sale_date?->format('d M Y  h:i A') }}</td>
            </tr>
            <tr>
                <td class="k">Cashier</td>
                <td>{{ $sale->cashier?->name ?? '—' }}</td>
            </tr>
            @if ($sale->customer_name)
                <tr>
                    <td class="k">Customer</td>
                    <td>{{ $sale->customer_name }}</td>
                </tr>
            @endif
        </table>

        <hr>

        <table class="items">
            @foreach ($sale->items as $item)
                <tr>
                    <td colspan="3">{{ $item->product?->name ?? 'Item' }}</td>
                </tr>
                @if ($item->product?->medicine?->strength || $item->batch?->batch_number)
                    <tr>
                        <td colspan="3" class="line-note">
                            @if ($item->product?->medicine?->strength)
                                {{ $item->product->medicine->strength }}
                                {{ $item->product->medicine->dosage_form }}
                            @endif
                            @if ($item->batch?->batch_number)
                                &middot; Lot {{ $item->batch->batch_number }}
                            @endif
                            @if ($item->batch?->expiration_date)
                                &middot; Exp {{ $item->batch->expiration_date->format('m/Y') }}
                            @endif
                        </td>
                    </tr>
                @endif
                <tr>
                    <td class="qty">{{ $item->quantity }}x</td>
                    <td class="muted">{{ $item->unit_label }} @ {{ Money::plain($item->unit_price) }}</td>
                    <td class="amt">{{ Money::plain($item->subtotal) }}</td>
                </tr>
                @if ((float) $item->discount > 0)
                    <tr>
                        <td class="qty"></td>
                        <td class="line-note">Less discount</td>
                        <td class="amt line-note">-{{ Money::plain($item->discount) }}</td>
                    </tr>
                @endif
            @endforeach
        </table>

        <hr>

        <table class="totals">
            <tr>
                <td>Subtotal</td>
                <td class="amt">{{ Money::plain($sale->subtotal) }}</td>
            </tr>
            @if ((float) $sale->discount > 0)
                <tr>
                    <td>
                        Discount
                        @if ($sale->discount_type && $sale->discount_type->value !== 'NONE')
                            ({{ $sale->discount_type->getLabel() }})
                        @endif
                    </td>
                    <td class="amt">-{{ Money::plain($sale->discount) }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>TOTAL</td>
                <td class="amt">{{ Money::symbol() }}{{ Money::plain($sale->total_amount) }}</td>
            </tr>
            <tr>
                <td>{{ $sale->payment_method?->getLabel() ?? 'Paid' }}</td>
                <td class="amt">{{ Money::plain($sale->amount_paid) }}</td>
            </tr>
            @if ($sale->payment_reference)
                <tr>
                    <td class="muted">Ref. no.</td>
                    <td class="amt">{{ $sale->payment_reference }}</td>
                </tr>
            @endif
            <tr>
                <td>Change</td>
                <td class="amt">{{ Money::plain($sale->change_amount) }}</td>
            </tr>
        </table>


        @if ($sale->discount_id_number)
            <hr>
            <table class="meta">
                <tr>
                    <td class="k">{{ $sale->discount_type?->getLabel() ?? 'Discount' }} ID</td>
                    <td>{{ $sale->discount_id_number }}</td>
                </tr>
                <tr>
                    <td class="k">Signature</td>
                    <td>________________</td>
                </tr>
            </table>
        @endif

        @if ($sale->remarks)
            <hr>
            <div class="muted">{{ $sale->remarks }}</div>
        @endif

        <hr>

        <div class="center footer">
            <div>{{ $sale->items->sum('quantity') }} item(s) &middot; {{ $sale->items->count() }} line(s)</div>
            @if (! empty($store['permit_number']))
                <div class="muted">Permit No. {{ $store['permit_number'] }}</div>
            @endif
            <div style="margin-top: 6px;">{{ $store['footer'] ?? 'Thank you!' }}</div>
            <div class="muted">Please keep this receipt for exchanges.</div>
        </div>
    </div>
</body>
</html>
