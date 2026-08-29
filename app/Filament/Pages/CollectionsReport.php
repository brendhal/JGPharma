<?php

namespace App\Filament\Pages;

use App\Enums\PaymentMethod;
use App\Models\Sale;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The screen you open before cashing out the e-wallet.
 *
 * It answers the one question up front — how much is sitting with GCash and
 * the card terminal right now — and prints the reference numbers behind that
 * figure so it can be checked against the provider's own statement.
 */
class CollectionsReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Collections';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.collections-report';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Cashiers ring the sales up; moving the money is the owner's job. */
    public static function canAccess(): bool
    {
        return auth()->user()?->isManagement() ?? false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'E-wallet & card collections';
    }

    public function getSubheading(): ?string
    {
        return 'Every GCash and card payment with its reference number, ready to reconcile and withdraw.';
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
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly',
                        'yearly' => 'Yearly',
                    ])
                    ->default('monthly')
                    ->required()
                    ->native(false),

                Forms\Components\DatePicker::make('date')
                    ->label('Any date inside the period')
                    ->helperText('The report covers the week, month or year containing this date.')
                    ->default(now())
                    ->maxDate(now())
                    ->required(),

                Forms\Components\Select::make('method')
                    ->label('Payment method')
                    ->options([
                        '' => 'GCash & Card together',
                        PaymentMethod::GCASH->value => 'GCash only',
                        PaymentMethod::CARD->value => 'Card only',
                    ])
                    ->default('')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->helperText('The two settle against different statements, so they print apart as well as together.'),
            ])
            ->columns(3)
            ->statePath('data');
    }

    public function openReport()
    {
        $data = $this->form->getState();

        return redirect()->route('collections.report', array_filter([
            'period' => $data['period'],
            'date' => $data['date'],
            'method' => $data['method'] ?? null,
        ]));
    }

    /**
     * This month's takings, one provider at a time.
     *
     * Split rather than summed, because they are withdrawn separately — a
     * combined figure is not a number anybody acts on. Read straight off
     * completed sales: nothing here knows what has already been withdrawn,
     * so these are "what the shop took", not "what is still owed".
     *
     * @return array<int, array{label:string, amount:string, caption:string}>
     */
    public function getSummary(): array
    {
        $take = fn (PaymentMethod $method): float => (float) Sale::query()
            ->completed()
            ->where('payment_method', $method->value)
            ->whereBetween('sale_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total_amount');

        return collect(PaymentMethod::cases())
            ->filter(fn (PaymentMethod $method) => $method->requiresReference())
            ->map(fn (PaymentMethod $method): array => [
                'label' => $method->getLabel().' this month',
                'amount' => Money::format($take($method)),
                'caption' => now()->format('F Y'),
            ])
            ->values()
            ->all();
    }

    /**
     * One row of print buttons per provider, plus a row for both together.
     *
     * A withdrawal is made from one provider at a time, so the common case is
     * "GCash, this month" — two clicks away from anywhere would be one click
     * too many for the thing this page exists to do.
     *
     * @return array<int, array{label:string, buttons:array<int, array{label:string, url:string}>}>
     */
    public function getShortcutRows(): array
    {
        $today = now()->toDateString();

        $periods = [
            'weekly' => 'This week',
            'monthly' => 'This month',
            'yearly' => 'This year',
        ];

        $row = fn (string $label, ?string $method): array => [
            'label' => $label,
            'buttons' => collect($periods)
                ->map(fn (string $text, string $period): array => [
                    'label' => $text,
                    'url' => route('collections.report', array_filter([
                        'period' => $period,
                        'date' => $today,
                        'method' => $method,
                    ])),
                ])
                ->values()
                ->all(),
        ];

        return [
            $row('GCash', PaymentMethod::GCASH->value),
            $row('Card', PaymentMethod::CARD->value),
            $row('Both together', null),
        ];
    }
}
