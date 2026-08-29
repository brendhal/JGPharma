@php
    use App\Support\Money;

    $comparison = $this->getComparison();
    $rows = $comparison['rows'];
    $suppliers = $comparison['suppliers'];
@endphp

<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->getSummary() as $stat)
            <x-filament::section>
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $stat['label'] }}
                </div>
                <div @class([
                    'mt-1 text-3xl font-bold tracking-tight',
                    'text-gray-950 dark:text-white' => $stat['tone'] !== 'success',
                    'text-success-600 dark:text-success-400' => $stat['tone'] === 'success',
                ])>
                    {{ $stat['value'] }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $stat['caption'] }}
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Narrow it down</x-slot>
        <x-slot name="description">
            The table below follows these filters, and so does the printable sheet.
        </x-slot>

        <form wire:submit="openReport" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" icon="heroicon-o-printer">
                    Open printable comparison
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    @if ($rows->isEmpty())
        <x-filament::section>
            <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                Nothing to compare with these filters. A price only appears here once the
                item has been on a purchase order, so widen the window or turn off
                &ldquo;more than one supplier&rdquo; to see single-source items.
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Price per supplier</x-slot>
            <x-slot name="description">
                One row per item, cheapest price highlighted. Ordered by what the gap has cost so far.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pe-3 text-start font-medium">Item</th>
                            <th class="py-2 px-3 text-end font-medium">Bought</th>
                            @foreach ($suppliers as $supplier)
                                <th class="py-2 px-3 text-end font-medium whitespace-nowrap">{{ $supplier->name }}</th>
                            @endforeach
                            <th class="py-2 px-3 text-start font-medium">Order from</th>
                            <th class="py-2 ps-3 text-end font-medium">Overspend</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            @php
                                $product = $row['product'];
                                $best = $row['best'];
                            @endphp
                            <tr>
                                <td class="py-2 pe-3 align-top">
                                    <div class="font-medium text-gray-950 dark:text-white">{{ $product->name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $product->sku }}
                                        @if ($product->category) &middot; {{ $product->category->name }} @endif
                                        &middot; per {{ $product->unit ?: 'unit' }}
                                    </div>
                                </td>
                                <td class="py-2 px-3 text-end align-top tabular-nums whitespace-nowrap text-gray-600 dark:text-gray-300">
                                    {{ number_format($row['quantity']) }}
                                </td>

                                @foreach ($suppliers as $supplier)
                                    @php $quote = $row['quotes'][$supplier->id] ?? null; @endphp
                                    @if (! $quote)
                                        <td class="py-2 px-3 text-center align-top text-gray-300 dark:text-gray-600">&mdash;</td>
                                    @else
                                        <td @class([
                                            'py-2 px-3 text-end align-top tabular-nums whitespace-nowrap',
                                            'font-semibold text-success-600 dark:text-success-400' => $quote['is_best'],
                                            'text-gray-600 dark:text-gray-300' => ! $quote['is_best'],
                                        ])>
                                            {{ Money::plain($quote['price']) }}
                                            <div class="text-xs font-normal text-gray-400 dark:text-gray-500">
                                                @if ($quote['is_best'])
                                                    {{ $quote['date']->format('d M y') }}
                                                @else
                                                    +{{ number_format($quote['difference_percent'], 1) }}%
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                @endforeach

                                <td class="py-2 px-3 align-top">
                                    <div class="font-medium text-success-600 dark:text-success-400">
                                        {{ $best['supplier']->name }}
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ Money::format($best['price']) }}
                                        @if ($product->isDivisible())
                                            &middot; {{ Money::plain($product->costPerPiece($best['price'])) }}/{{ $product->piece_unit ?: 'pc' }}
                                        @endif
                                    </div>
                                </td>
                                <td @class([
                                    'py-2 ps-3 text-end align-top tabular-nums whitespace-nowrap',
                                    'text-danger-600 dark:text-danger-400' => $row['overspend'] > 0,
                                    'text-gray-400 dark:text-gray-500' => $row['overspend'] <= 0,
                                ])>
                                    {{ Money::plain($row['overspend']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($this->getBiggestGaps()->isNotEmpty())
            <x-filament::section collapsible>
                <x-slot name="heading">Worth a phone call</x-slot>
                <x-slot name="description">
                    The widest gaps between the cheapest and dearest supplier for the same item.
                </x-slot>

                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @foreach ($this->getBiggestGaps() as $row)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                            <div>
                                <span class="font-medium text-gray-950 dark:text-white">{{ $row['product']->name }}</span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    &mdash; {{ $row['best']['supplier']->name }} at {{ Money::format($row['best']['price']) }},
                                    {{ $row['worst']['supplier']->name }} at {{ Money::format($row['worst']['price']) }}
                                </span>
                            </div>
                            <div class="text-end">
                                <span class="font-semibold text-danger-600 dark:text-danger-400">
                                    {{ Money::format($row['spread']) }} apart
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    ({{ number_format($row['spread_percent'], 1) }}%)
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Where these prices come from</x-slot>

        <ul class="list-disc space-y-1 ps-5 text-sm text-gray-600 dark:text-gray-400">
            <li>A supplier's price is the cost on their most recent purchase order for that item — pending orders included, cancelled ones ignored.</li>
            <li>Prices are per whole pack, the unit a purchase order is written in, so two suppliers are always compared on the same thing.</li>
            <li>&ldquo;Overspend&rdquo; is what the quantity already ordered would have cost at the cheapest price on the row.</li>
            <li>A dash means that item has never been ordered from that supplier — ask for a quote, put it on an order, and it will appear here.</li>
            <li>Price is not the whole decision: delivery time, expiry dating, minimum order and credit terms are not tracked in this system.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
