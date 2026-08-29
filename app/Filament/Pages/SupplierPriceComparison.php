<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Services\SupplierPriceComparison as Comparison;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Where you decide who gets which line of the next order.
 *
 * The same item is often carried by more than one supplier at more than one
 * price, and nothing in the system put those prices next to each other — so
 * an order went to whoever it went to last time. This lays them side by side,
 * marks the cheapest, and adds up what habit has been costing.
 *
 * The filters are live: the table below redraws as they change, and the print
 * button carries the same filters through to the printable sheet.
 */
class SupplierPriceComparison extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Supplier Prices';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.supplier-price-comparison';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * The comparison is read three times while the page renders — summary,
     * gap list, table — and it is the expensive part. One build per request.
     *
     * @var array{filters:string, result:array<string, mixed>}|null
     */
    protected ?array $memoised = null;

    /** Cost prices are management's business, not the floor's. */
    public static function canAccess(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Supplier price comparison';
    }

    public function getSubheading(): ?string
    {
        return 'What each supplier charges for the same item, so an order can be split on price.';
    }

    public function mount(): void
    {
        $this->form->fill([
            'months' => Comparison::DEFAULT_MONTHS,
            'category' => null,
            'search' => '',
            'multi_only' => true,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('months')
                    ->label('Prices from')
                    ->options(Comparison::WINDOWS)
                    ->default(Comparison::DEFAULT_MONTHS)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->helperText('How far back to look for a price.'),

                Forms\Components\Select::make('category')
                    ->label('Category')
                    ->placeholder('All categories')
                    ->options(fn (): array => Category::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->native(false)
                    ->live(),

                Forms\Components\TextInput::make('search')
                    ->label('Item')
                    ->placeholder('Name, generic, SKU or barcode')
                    ->live(debounce: 500),

                Forms\Components\Toggle::make('multi_only')
                    ->label('Only items with more than one supplier')
                    ->default(true)
                    ->live()
                    ->helperText('Turn off to see single-source items too.'),
            ])
            ->columns(4)
            ->statePath('data');
    }

    /** Open the printable sheet for whatever the filters are showing. */
    public function openReport()
    {
        return redirect()->route('suppliers.price-comparison', $this->reportQuery());
    }

    /**
     * The comparison behind the on-screen table.
     *
     * @return array<string, mixed>
     */
    public function getComparison(): array
    {
        $filters = $this->filters();
        $key = md5(serialize($filters));

        if ($this->memoised === null || $this->memoised['filters'] !== $key) {
            $this->memoised = [
                'filters' => $key,
                'result' => app(Comparison::class)->build($filters),
            ];
        }

        return $this->memoised['result'];
    }

    /**
     * The headline figures, in the order they answer the question.
     *
     * @return array<int, array{label:string, value:string, caption:string, tone:string}>
     */
    public function getSummary(): array
    {
        $comparison = $this->getComparison();
        $totals = $comparison['totals'];

        return [
            [
                'label' => 'Items compared',
                'value' => number_format($totals['products']),
                'caption' => number_format($totals['comparable']).' with more than one supplier',
                'tone' => 'default',
            ],
            [
                'label' => 'Suppliers quoting',
                'value' => number_format($totals['suppliers']),
                'caption' => 'Across the items shown',
                'tone' => 'default',
            ],
            [
                'label' => 'Spent on these items',
                'value' => Money::format($totals['spend']),
                'caption' => 'Ordered in this window',
                'tone' => 'default',
            ],
            [
                'label' => 'Could have been saved',
                'value' => Money::format($totals['overspend']),
                'caption' => 'Same volume at the cheapest price',
                'tone' => 'success',
            ],
        ];
    }

    /**
     * The lines where switching supplier is worth the phone call.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBiggestGaps(int $limit = 10): Collection
    {
        return collect($this->getComparison()['rows'])
            ->filter(fn (array $row): bool => count($row['quotes']) > 1 && $row['spread'] > 0)
            ->take($limit);
    }

    /**
     * The filter state, read straight off the component.
     *
     * Not through the form's getState(), which validates: this runs on every
     * render, and a half-typed search box should redraw the table, not throw.
     *
     * @return array<string, mixed>
     */
    protected function filters(): array
    {
        $data = $this->data ?? [];

        return [
            'months' => $data['months'] ?? Comparison::DEFAULT_MONTHS,
            'category' => $data['category'] ?? null,
            'search' => $data['search'] ?? '',
            'multi_only' => (bool) ($data['multi_only'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    protected function reportQuery(): array
    {
        $filters = $this->filters();

        return array_filter([
            'months' => $filters['months'],
            'category' => $filters['category'],
            'search' => $filters['search'],
            'all' => $filters['multi_only'] ? null : 1,
        ], fn ($value): bool => $value !== null && $value !== '');
    }
}
