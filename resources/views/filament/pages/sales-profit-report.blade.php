<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Jump straight to a period</x-slot>
        <x-slot name="description">
            Opens the printable report for the current day, week, month or year.
        </x-slot>

        <div class="flex flex-wrap gap-3">
            @foreach ($this->getShortcuts() as $shortcut)
                <x-filament::button
                    tag="a"
                    href="{{ $shortcut['url'] }}"
                    target="_blank"
                    color="gray"
                    icon="heroicon-o-printer"
                >
                    {{ $shortcut['label'] }}
                </x-filament::button>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Choose a different period</x-slot>
        <x-slot name="description">
            Pick any date and the report covers the day, week, month or year around it.
        </x-slot>

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
        <x-slot name="heading">What the report shows</x-slot>

        <ul class="fi-ta-text-item-label text-sm text-gray-600 dark:text-gray-400 space-y-1 list-disc ps-5">
            <li>Total sales, cost of goods sold, gross profit and margin for the period.</li>
            <li>A row per day — or per month on a yearly report — with the same figures.</li>
            <li>The fifteen products that earned the most, ranked by profit rather than units.</li>
            <li>Profit by category and by cashier.</li>
            <li>Every transaction, with its items and the profit it made.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
