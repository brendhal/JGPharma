<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasLabel
{
    case ADMIN = 'ADMIN';
    case MANAGER = 'MANAGER';
    case CASHIER = 'CASHIER';

    public function getLabel(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrator',
            self::MANAGER => 'Branch Manager',
            self::CASHIER => 'Cashier',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ADMIN => 'danger',
            self::MANAGER => 'warning',
            self::CASHIER => 'info',
        };
    }

    /** Roles that see every branch, not just their own. */
    public function seesAllBranches(): bool
    {
        return $this === self::ADMIN;
    }

    /** Roles allowed into the back office (catalog, purchasing, users). */
    public function isManagement(): bool
    {
        return in_array($this, [self::ADMIN, self::MANAGER], true);
    }

    /** Floor staff: the POS terminal and their own sales, nothing else. */
    public function isFloorStaff(): bool
    {
        return $this === self::CASHIER;
    }

    /** Roles allowed to ring up a sale. */
    public function canSell(): bool
    {
        return true;
    }
}
