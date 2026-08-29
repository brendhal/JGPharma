@php
    use App\Support\Money;
@endphp

@if ($sales->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Nothing rung up at this till yet today.
    </p>
@else
    <div class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($sales as $sale)
            <div class="flex items-center justify-between gap-4 py-2">
                <div class="min-w-0">
                    <div class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ $sale->invoice_number }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $sale->sale_date->format('h:i A') }}
                        &middot; {{ $sale->payment_method?->getLabel() ?? 'Paid' }}
                        @if ($sale->customer_name)
                            &middot; {{ $sale->customer_name }}
                        @endif
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                        {{ Money::format($sale->total_amount) }}
                    </span>

                    <x-filament::button
                        tag="a"
                        href="{{ route('receipts.show', $sale) }}"
                        target="_blank"
                        size="sm"
                        color="gray"
                        icon="heroicon-o-printer"
                    >
                        Print
                    </x-filament::button>
                </div>
            </div>
        @endforeach
    </div>
@endif
