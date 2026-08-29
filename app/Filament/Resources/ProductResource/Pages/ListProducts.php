<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\ProductCatalogStats;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),

            Actions\CreateAction::make()
                ->label('Add new product')
                ->icon('heroicon-o-plus-circle'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProductCatalogStats::class,
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $filename = 'products-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Barcode', 'SKU', 'Generic name', 'Brand name', 'Unit', 'Pieces per unit', 'Cost', 'Price', 'Reorder level (units)', 'Reorder level (pieces)']);

            Product::with('medicine')->chunk(200, function ($products) use ($handle) {
                foreach ($products as $product) {
                    fputcsv($handle, [
                        $product->barcode,
                        $product->sku,
                        $product->medicine?->generic_name,
                        $product->name,
                        $product->unit,
                        $product->pieces_per_box,
                        $product->cost_price,
                        $product->selling_price,
                        // Both readings, because the sheet is read by people
                        // who order in boxes and by a system that counts pieces.
                        (int) ceil($product->minimum_stock / max(1, (int) $product->pieces_per_box)),
                        $product->minimum_stock,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
