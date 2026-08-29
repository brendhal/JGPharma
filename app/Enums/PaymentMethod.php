<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasIcon, HasLabel
{
    case CASH = 'CASH';
    case CARD = 'CARD';
    case GCASH = 'GCASH';

    public function getLabel(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::CARD => 'Card',
            self::GCASH => 'GCash',
        };
    }

    /**
     * Whether the sale cannot be committed without a transaction reference.
     *
     * Cash lands in the drawer and is counted at close. GCash and card do not
     * — the only trace of the money is the number the terminal or the wallet
     * app hands back, so the cashier records it before the sale is taken.
     */
    public function requiresReference(): bool
    {
        return $this !== self::CASH;
    }

    /** What the cashier is being asked for, in the words on their screen. */
    public function referenceLabel(): string
    {
        return match ($this) {
            self::GCASH => 'GCash reference number',
            self::CARD => 'Card approval number',
            self::CASH => 'Reference number',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::CASH => 'heroicon-m-banknotes',
            self::CARD => 'heroicon-m-credit-card',
            self::GCASH => 'heroicon-m-device-phone-mobile',
        };
    }
}
