<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    public function getTitle(): string
    {
        return "Invoice {$this->record->invoice_number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('Print receipt')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('receipts.show', $this->record))
                ->openUrlInNewTab(),
        ];
    }
}
