<?php

namespace App\Filament\Resources\ProductBatchResource\Pages;

use App\Filament\Resources\ProductBatchResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageProductBatches extends ManageRecords
{
    protected static string $resource = ProductBatchResource::class;

    protected static ?string $title = 'Batches & expiry tracking';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add batch manually')
                ->icon('heroicon-o-plus'),
        ];
    }
}
