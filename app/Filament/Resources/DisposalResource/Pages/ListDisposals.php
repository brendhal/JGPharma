<?php

namespace App\Filament\Resources\DisposalResource\Pages;

use App\Filament\Resources\DisposalResource;
use App\Models\Supplier;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListDisposals extends ListRecords
{
    protected static string $resource = DisposalResource::class;

    public function getHeading(): string|Htmlable
    {
        return 'Disposals';
    }

    public function getSubheading(): ?string
    {
        return 'Expired and damaged stock written off, and what it cost.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('printReport')
                ->label('Print disposal report')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->modalHeading('Print disposal report')
                ->modalSubmitActionLabel('Open report')
                ->form([
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

                    Forms\Components\Select::make('reason')
                        ->label('Reason')
                        ->placeholder('Expired and damaged')
                        ->options([
                            'EXPIRED' => 'Expired only',
                            'DAMAGED' => 'Damaged only',
                        ])
                        ->native(false)
                        ->helperText('Leave blank to cover both, subtotalled separately.'),
                ])
                ->action(fn (array $data) => redirect()->route('disposals.report', array_filter([
                    'period' => $data['period'],
                    'date' => $data['date'],
                    'reason' => $data['reason'] ?? null,
                ]))),

            Actions\Action::make('printSupplierClaim')
                ->label('Print supplier claim')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->modalHeading('Print supplier claim')
                ->modalDescription('Written-off stock sorted by the supplier it was bought from, ready to send for refund or replacement.')
                ->modalSubmitActionLabel('Open claim')
                ->form([
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
                        ->helperText('The claim covers the day, week, month or year containing this date.')
                        ->default(now())
                        ->maxDate(now())
                        ->required(),

                    Forms\Components\Select::make('supplier')
                        ->label('Supplier')
                        ->placeholder('Every supplier, one sheet each')
                        ->options(fn (): array => Supplier::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->helperText('Leave blank to print a separate, page-broken sheet per supplier.'),

                    Forms\Components\Select::make('reason')
                        ->label('Reason')
                        ->options([
                            'EXPIRED' => 'Expired only',
                            'DAMAGED' => 'Damaged only',
                            'all' => 'Expired and damaged',
                        ])
                        ->default('EXPIRED')
                        ->required()
                        ->native(false)
                        ->helperText('Suppliers usually accept expired pull-outs only.'),
                ])
                ->action(fn (array $data) => redirect()->route('disposals.claims', array_filter([
                    'period' => $data['period'],
                    'date' => $data['date'],
                    'supplier' => $data['supplier'] ?? null,
                    'reason' => $data['reason'] ?? null,
                ]))),
        ];
    }
}
