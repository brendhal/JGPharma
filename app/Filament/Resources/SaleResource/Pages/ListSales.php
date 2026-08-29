<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Pages\PointOfSale;
use App\Filament\Resources\SaleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    /**
     * Just the till, from here.
     *
     * Printing used to start on this screen, which meant a report about last
     * month was reached by way of a list of today's tickets. The Reports menu
     * carries Sales & Profit and Collections now, so the button is gone and
     * this page is what its name says: sales history.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('newSale')
                ->label('New sale')
                ->icon('heroicon-o-shopping-cart')
                ->url(fn (): string => PointOfSale::getUrl()),
        ];
    }
}
