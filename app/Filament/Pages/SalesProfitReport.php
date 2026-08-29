<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where the owner goes to ask what the shop made.
 *
 * The printable report itself already existed, but only behind a button on
 * the sales list — somewhere nobody looks when the question is "how did last
 * month go". This puts it in the Reports menu with the period to hand, and
 * shortcuts for the four periods that get asked for most.
 */
class SalesProfitReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Sales & Profit';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.sales-profit-report';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Cost prices and margins are management's business, not the floor's. */
    public static function canAccess(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Sales & profit report';
    }

    public function getSubheading(): ?string
    {
        return 'Printable takings and gross profit for a day, week, month or year.';
    }

    public function mount(): void
    {
        $this->form->fill([
            'period' => 'monthly',
            'date' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('period')
                    ->label('Period')
                    ->options([
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly',
                        'yearly' => 'Yearly',
                    ])
                    ->default('monthly')
                    ->required()
                    ->native(false),

                Forms\Components\DatePicker::make('date')
                    ->label('Any date inside the period')
                    ->helperText('The report covers the day, week, month or year containing this date.')
                    ->default(now())
                    ->maxDate(now())
                    ->required(),

                Forms\Components\Select::make('cashier')
                    ->label('Cashier')
                    ->placeholder('All cashiers')
                    ->options(fn (): array => $this->cashierOptions())
                    ->searchable()
                    ->native(false)
                    ->helperText('Leave blank to cover everyone.'),
            ])
            ->columns(3)
            ->statePath('data');
    }

    /** Open the printable report for whatever the form is currently showing. */
    public function openReport()
    {
        $data = $this->form->getState();

        return redirect()->route('sales.report', array_filter([
            'period' => $data['period'],
            'date' => $data['date'],
            'cashier' => $data['cashier'] ?? null,
        ]));
    }

    /**
     * The four questions that get asked without anyone touching the form.
     *
     * @return array<int, array{key:string, label:string, url:string}>
     */
    public function getShortcuts(): array
    {
        $today = now()->toDateString();

        return collect([
            'daily' => 'Today',
            'weekly' => 'This week',
            'monthly' => 'This month',
            'yearly' => 'This year',
        ])->map(fn (string $label, string $period): array => [
            'key' => $period,
            'label' => $label,
            'url' => route('sales.report', ['period' => $period, 'date' => $today]),
        ])->values()->all();
    }

    /**
     * Who this user may run a report on, name => id.
     *
     * @return array<int, string>
     */
    protected function cashierOptions(): array
    {
        $user = auth()->user();

        return User::query()
            ->where('is_active', true)
            ->when(
                ! $user?->seesAllBranches(),
                fn (Builder $query) => $query->where('branch_id', $user?->branch_id)
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
