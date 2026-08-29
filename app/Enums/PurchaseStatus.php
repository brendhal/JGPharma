<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PurchaseStatus: string implements HasColor, HasLabel
{
    case PENDING = 'PENDING';
    case RECEIVED = 'RECEIVED';
    case CANCELLED = 'CANCELLED';

    public function getLabel(): string
    {
        return ucfirst(strtolower($this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::RECEIVED => 'success',
            self::CANCELLED => 'danger',
        };
    }
}
