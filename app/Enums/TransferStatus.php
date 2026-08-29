<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransferStatus: string implements HasColor, HasLabel
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
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
            self::APPROVED => 'info',
            self::RECEIVED => 'success',
            self::CANCELLED => 'danger',
        };
    }
}
