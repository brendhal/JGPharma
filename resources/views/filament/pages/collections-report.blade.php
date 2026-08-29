<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ($this->getSummary() as $stat)
            <x-filament::section>
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $stat['label'] }}
                </div>
                <div class="mt-1 text-3xl font-bold tracking-tight text-gray-950 dark:text-white">
                    {{ $stat['amount'] }}
                </div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $stat['caption'] }}
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Print a collections report</x-slot>
        <x-slot name="description">
            GCash and card settle against different statements, so each one prints on its own.
        </x-slot>

        <div class="space-y-4">
            @foreach ($this->getShortcutRows() as $row)
                <div class="flex flex-wrap items-center gap-3">
                    <div class="w-32 shrink-0 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $row['label'] }}
                    </div>
                    @foreach ($row['buttons'] as $button)
                        <x-filament::button
                            tag="a"
                            href="{{ $button['url'] }}"
                            target="_blank"
                            color="gray"
                            icon="heroicon-o-printer"
                        >
                            {{ $button['label'] }}
                        </x-filament::button>
                    @endforeach
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Choose a different period</x-slot>

        <form wire:submit="openReport" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" icon="heroicon-o-printer">
                    Open printable report
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">How to use it when withdrawing</x-slot>

        <ol class="text-sm text-gray-600 dark:text-gray-400 space-y-1 list-decimal ps-5">
            <li>Print the GCash sheet for the period you are cashing out.</li>
            <li>Open your GCash transaction history for the same dates.</li>
            <li>Tick each line off against the app — the reference numbers should match one for one.</li>
            <li>The day-by-day subtotals should agree with what GCash shows for each day.</li>
            <li>Withdraw, then write the amount and date in the box at the bottom and file the sheet.</li>
            <li>Do the same with the card sheet against the terminal's settlement report.</li>
        </ol>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            The figures are what the shop took. Nothing here tracks what you have already
            withdrawn, so a period you have cashed out will still show its full total.
        </p>
    </x-filament::section>
</x-filament-panels::page>
