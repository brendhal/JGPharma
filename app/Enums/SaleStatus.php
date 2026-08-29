<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SaleStatus: string implements HasColor, HasLabel
{
    case COMPLETED = 'COMPLETED';
    case HELD = 'HELD';
    case VOIDED = 'VOIDED';
    case REFUNDED = 'REFUNDED';

    public function getLabel(): string
    {
        return ucfirst(strtolower($this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::COMPLETED => 'success',
            self::HELD => 'warning',
            self::VOIDED => 'danger',
            self::REFUNDED => 'info',
        };
    }
}
