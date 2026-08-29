<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Enums\PurchaseStatus;
use App\Filament\Resources\PurchaseResource;
use App\Services\InventoryService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('receive')
                ->label('Receive delivery')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === PurchaseStatus::PENDING)
                ->action(function (InventoryService $inventory) {
                    try {
                        $inventory->receivePurchase($this->record);

                        Notification::make()
                            ->title('Delivery received')
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Could not receive delivery')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('print')
                ->label('Print purchase order')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('purchases.print', $this->record))
                ->openUrlInNewTab(),

            Actions\DeleteAction::make()
                ->visible(fn (): bool => $this->record->status !== PurchaseStatus::RECEIVED),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
