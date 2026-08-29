<?php

namespace App\Filament\Resources\BranchInventoryResource\Pages;

use App\Filament\Resources\BranchInventoryResource;
use Filament\Resources\Pages\ListRecords;

class ListBranchInventories extends ListRecords
{
    protected static string $resource = BranchInventoryResource::class;

    protected static ?string $title = 'Stock levels';
}
