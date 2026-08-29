<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum MovementType: string implements HasColor, HasIcon, HasLabel
{
    case PURCHASE = 'PURCHASE';
    case SALE = 'SALE';
    case RETURN = 'RETURN';
    case EXPIRED = 'EXPIRED';
    case DAMAGED = 'DAMAGED';
    case ADJUSTMENT = 'ADJUSTMENT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';

    public function getLabel(): string
    {
        return match ($this) {
            self::PURCHASE => 'Stock In (Purchase)',
            self::SALE => 'Sale',
            self::RETURN => 'Customer Return',
            self::EXPIRED => 'Expired Disposal',
            self::DAMAGED => 'Damaged',
            self::ADJUSTMENT => 'Adjustment',
            self::TRANSFER_IN => 'Transfer In',
            self::TRANSFER_OUT => 'Transfer Out',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PURCHASE, self::RETURN, self::TRANSFER_IN => 'success',
            self::SALE, self::TRANSFER_OUT => 'info',
            self::EXPIRED, self::DAMAGED => 'danger',
            self::ADJUSTMENT => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::PURCHASE => 'heroicon-m-arrow-down-tray',
            self::SALE => 'heroicon-m-shopping-cart',
            self::RETURN => 'heroicon-m-arrow-uturn-left',
            self::EXPIRED => 'heroicon-m-calendar-days',
            self::DAMAGED => 'heroicon-m-exclamation-triangle',
            self::ADJUSTMENT => 'heroicon-m-adjustments-horizontal',
            self::TRANSFER_IN => 'heroicon-m-arrow-right-circle',
            self::TRANSFER_OUT => 'heroicon-m-arrow-left-circle',
        };
    }

    /** Movement types that increase stock. */
    public function isInbound(): bool
    {
        return in_array($this, [self::PURCHASE, self::RETURN, self::TRANSFER_IN], true);
    }

    /** Types a user may pick manually when disposing of stock. */
    public static function disposalCases(): array
    {
        return [self::EXPIRED, self::DAMAGED];
    }
}
